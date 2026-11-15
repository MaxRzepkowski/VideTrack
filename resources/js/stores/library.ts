import { creatorKey, type Creator, type CreatorState, type CreatorStatesMap } from '@/lib/creators';
import { failureOf } from '@/lib/http';
import type { SharedData } from '@/types';
import type { UserMediaState } from '@/types/generated';
import type { MediaPosition, MediaSummary, UserStatesMap } from '@/types/media';
import { router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, reactive, ref, toValue, watch, watchEffect, type ComputedRef, type MaybeRefOrGetter } from 'vue';
import { toast } from 'vue-sonner';

/*
 * The library as the page knows it: what the user has done with every title on
 * screen, keyed "type:ref", and with every creator, keyed "kind:provider:ref".
 * Fed by the page (its `userStates` and `creatorStates` props, deferred
 * sections, streamed chunks) and by the answer to every write, which carries
 * every state the write touched — the title and the containers above it, or
 * the creator. The front never guesses a state: what it shows is what the
 * server last said.
 *
 * Memory only, and forgotten on leaving the page. The one Inertia visit made
 * here is the reload a write asks for — always after a click, never in the
 * background, where it would repaint the page under the user.
 */

const states = reactive(new Map<string, UserMediaState>());
const creators = reactive(new Map<string, CreatorState>());

export function stateKey(media: MediaPosition): string {
    return `${media.type}:${media.ref}`;
}

/** Take in states the server sent: a page prop, a streamed chunk, the answer to a write. */
export function absorbUserStates(incoming: UserStatesMap): void {
    for (const [key, state] of Object.entries(incoming)) {
        states.set(key, state);
    }
}

/** Take in creator states the server sent: a page prop or the answer to a write. */
export function absorbCreatorStates(incoming: CreatorStatesMap): void {
    for (const [key, state] of Object.entries(incoming)) {
        creators.set(key, state);
    }
}

const UNTOUCHED_CREATOR: CreatorState = { followed: false, listIds: [] };

/** A creator's bell and lists; one nobody touched has no bell and no lists. */
export function creatorState(creator: Creator): CreatorState {
    return creators.get(creatorKey(creator)) ?? UNTOUCHED_CREATOR;
}

// The state of a title nobody touched, as the server words it; the same on
// every page of a signed-in user, kept here by `followPage`. The app's first
// render comes before `followPage` runs, so the first card to ask reads it
// off the page itself.
let untouchedState: UserMediaState | null = null;

function untouched(): UserMediaState {
    untouchedState ??= usePage<SharedData>().props.untouchedState;

    if (!untouchedState) {
        throw new Error('A page with titles on it was served without untouchedState.');
    }

    return untouchedState;
}

/** Where the title really stands, loader or no loader. */
export function settledState(key: string): UserMediaState {
    return states.get(key) ?? untouched();
}

// A write against a local database resolves in tens of milliseconds: a loader
// that lasts exactly as long as the request only blinks. The loader therefore
// stays up for at least this long — and unchanged, it covers a remote server
// that takes seconds.
const MIN_BUSY_MS = 300;

// What a title under the loader keeps showing: the state it had BEFORE the
// write. The real state lands with the answer, well before the loader is done,
// and letting it through would put the change ahead of the loader — the card
// would jump and only then announce it was working. Held back here, the change
// appears at the moment the loader lifts, which is what a loader means.
const frozen = reactive(new Map<string, UserMediaState>());

// Titles with a write on screen: one still in flight (`busyWrites`), or one
// that has landed and is serving out the minimum (`busyTail`).
const busyWrites = reactive(new Map<string, number>());
const busyTail = reactive(new Set<string>());
const busyStarted = new Map<string, number>();
const busyTimers = new Map<string, ReturnType<typeof setTimeout>>();

// Counts the pages left. A write still out when its page goes outlives it: its
// answer is still the server's word on the title, but its loader and its own
// reload props belonged to the cards of the page it was made on.
let pageVisit = 0;

function clearTail(key: string): void {
    const timer = busyTimers.get(key);

    if (timer !== undefined) {
        clearTimeout(timer);
        busyTimers.delete(key);
    }

    busyTail.delete(key);
}

function startBusy(key: string): void {
    // First write of a burst sets the picture the loader holds; the ones that
    // follow ride under the same loader and must not reset it to a state the
    // user has already been shown past.
    if (!frozen.has(key)) {
        frozen.set(key, settledState(key));
    }

    clearTail(key);
    busyWrites.set(key, (busyWrites.get(key) ?? 0) + 1);
    // Restarted per write, so a burst of clicks measures from the last one.
    busyStarted.set(key, Date.now());
}

function endBusy(key: string): void {
    const left = (busyWrites.get(key) ?? 1) - 1;

    if (left > 0) {
        busyWrites.set(key, left);

        return;
    }

    busyWrites.delete(key);

    const remaining = MIN_BUSY_MS - (Date.now() - (busyStarted.get(key) ?? 0));

    busyStarted.delete(key);

    if (remaining <= 0) {
        frozen.delete(key);

        return;
    }

    busyTail.add(key);
    busyTimers.set(
        key,
        setTimeout(() => {
            busyTimers.delete(key);
            busyTail.delete(key);
            frozen.delete(key);
        }, remaining),
    );
}

function isBusyKey(key: string): boolean {
    return (busyWrites.get(key) ?? 0) > 0 || busyTail.has(key);
}

/** What a card shows: the frozen picture while its loader is up, else the settled state. */
export function shownState(key: string): UserMediaState {
    return frozen.get(key) ?? settledState(key);
}

/** {@see shownState}, following the title a card is handed. */
export function displayedState(media: MaybeRefOrGetter<MediaSummary>): ComputedRef<UserMediaState> {
    return computed(() => shownState(stateKey(toValue(media))));
}

/**
 * Is this title under the loader? True while its write is in flight, and until
 * the minimum is served out.
 *
 * A card can be handed a different title mid-write: an Up Next card is keyed
 * by its show, so the write that marks an episode watched swaps the card over
 * to the next one. The loader belongs to the WRITE, not to whatever the card is
 * showing now — hence the latch: it holds the key it went up for and comes down
 * when that write is done, revealing the episode that took its place.
 */
export function useCardBusy(media: MaybeRefOrGetter<MediaSummary>): ComputedRef<boolean> {
    const key = computed(() => stateKey(toValue(media)));
    const held = ref<string | null>(null);

    watchEffect(() => {
        if (isBusyKey(key.value)) {
            held.value = key.value;
        }
    });

    return computed(() => held.value !== null && isBusyKey(held.value));
}

/*
 * What the page refreshes after a write: props of its own that depend on the
 * library (Up Next, a panel's entries), registered while the page is mounted.
 * It runs inside the write, so the loader stays up until the props land.
 *
 * Inertia runs reloads side by side, and the later one to land wins — an
 * older answer could put a picture from before the newest write back on the
 * page. So reloads go one at a time: a write that lands while one is out
 * joins the next, which starts after it and carries everything asked so far.
 */
const pageReloads = new Map<symbol, string[]>();

export function reloadAfterWrites(only: string[]): () => void {
    const id = Symbol('page');

    pageReloads.set(id, only);

    return () => pageReloads.delete(id);
}

const askedProps = new Set<string>();
let lastReload: Promise<void> = Promise.resolve();
let nextReload: Promise<void> | null = null;

function visitForProps(): Promise<void> {
    const only = [...new Set([...askedProps, ...[...pageReloads.values()].flat()])];

    askedProps.clear();

    if (only.length === 0) {
        return Promise.resolve();
    }

    return new Promise((resolve) => {
        router.reload({ only, onFinish: () => resolve() });
    });
}

function reloadPage(extra: string[]): Promise<void> {
    for (const prop of extra) {
        askedProps.add(prop);
    }

    nextReload ??= lastReload.then(() => {
        nextReload = null;

        return visitForProps();
    });
    lastReload = nextReload;

    return nextReload;
}

/**
 * Every write either lands or tells the user it did not — never a silent
 * revert. A refusal says why in its own words: trying again would not help.
 */
function failed(reason: string | null = null): void {
    toast.error(reason ?? trans('common.something_went_wrong_please_try_again'));
}

/**
 * One write to the library: the request goes out, the states in the answer are
 * taken in, and the page reloads what depends on them (plus `reload`, props the
 * write itself knows it changed). Answers whether the write landed.
 *
 * `send` answers null when, read at the moment it would go out, there is
 * nothing left to send: a shortcut the click before it already carried out.
 */
export async function writeLibrary(send: () => Promise<Response | null>, reload: string[] = []): Promise<boolean> {
    const visit = pageVisit;

    try {
        const response = await send();

        if (response === null) {
            return true;
        }

        if (!response.ok) {
            failed(await failureOf(response));

            // A write of several requests may have landed the first ones (a
            // quick-created list the title then failed to join): what it names
            // as changed is read again all the same, or a retry would make a
            // second list.
            if (reload.length > 0 && visit === pageVisit) {
                await reloadPage(reload);
            }

            return false;
        }

        // A write with nothing to report answers 204, no body.
        const answer = (response.status === 204 ? {} : await response.json()) as { states?: UserStatesMap; creatorStates?: CreatorStatesMap };

        absorbUserStates(answer.states ?? {});
        absorbCreatorStates(answer.creatorStates ?? {});
        // On a page left meanwhile, the page's own registrations still reload:
        // its props were asked while this write was out, maybe before it landed.
        await reloadPage(visit === pageVisit ? reload : []);

        return true;
    } catch (error) {
        // The request never got an answer (offline, the server down), or an
        // answer that is no JSON at all (a proxy's error page).
        if (error instanceof TypeError || error instanceof SyntaxError) {
            failed();

            return false;
        }

        throw error;
    }
}

// The last write queued per title and per creator. One thing's writes go one
// after another, so a second click builds its request from what the first one
// left behind, not from the picture held under the loader.
const titleWrites = new Map<string, Promise<boolean>>();
const creatorWrites = new Map<string, Promise<boolean>>();

async function inTurn(queue: Map<string, Promise<boolean>>, key: string, write: () => Promise<boolean>): Promise<boolean> {
    const earlier = queue.get(key);
    const turn = earlier === undefined ? write() : earlier.catch(() => false).then(write);

    queue.set(key, turn);

    try {
        return await turn;
    } finally {
        if (queue.get(key) === turn) {
            queue.delete(key);
        }
    }
}

/**
 * A write of one title's state, under that title's loader. `send` runs once the
 * title's earlier writes have landed, so a toggle reads the state it flips then.
 */
export async function writeState(media: MediaPosition, send: () => Promise<Response | null>, reload: string[] = []): Promise<boolean> {
    const key = stateKey(media);
    const visit = pageVisit;
    const write = inTurn(titleWrites, key, () => writeLibrary(send, reload));

    startBusy(key);

    try {
        return await write;
    } finally {
        if (visit === pageVisit) {
            endBusy(key);
        }
    }
}

/**
 * A write of one creator's bell or lists. `send` runs once the creator's
 * earlier writes have landed, so a toggle reads the state it flips then.
 */
export function writeCreator(creator: Creator, send: () => Promise<Response>, reload: string[] = []): Promise<boolean> {
    return inTurn(creatorWrites, creatorKey(creator), () => writeLibrary(send, reload));
}

/** Leaving the page: the cards these states belonged to are gone. */
function forgetPage(): void {
    states.clear();
    creators.clear();

    // A card the new page happens to key the same must not open already
    // spinning, nor wearing a state the page it was left on had frozen.
    for (const key of [...busyTail]) {
        clearTail(key);
    }

    busyWrites.clear();
    busyStarted.clear();
    frozen.clear();
    askedProps.clear();
    pageVisit += 1;
}

let followedUrl: string | null = null;

/**
 * Keep the store in step with the page: take in its `userStates` and
 * `creatorStates` whenever the page hands new ones, and start over on another address. Called once, at boot.
 */
export function followPage(): void {
    // Only the pages with titles or creators on them carry these.
    const page = usePage<SharedData & { userStates?: UserStatesMap; creatorStates?: CreatorStatesMap }>();

    watch(
        () => page.props.untouchedState,
        (state) => {
            untouchedState = state;
        },
        { immediate: true, flush: 'sync' },
    );

    // Separate sources, and each taken in only when it changed: a partial
    // reload that does not ask for `userStates` (or `creatorStates`) keeps the
    // old object, and re-taking it would put the page-load states back over
    // what writes have answered since.
    watch(
        [() => page.url, () => page.props.userStates, () => page.props.creatorStates],
        ([url, userStates, creatorStates], previous) => {
            const [, previousUserStates, previousCreatorStates] = previous ?? [];
            const newPage = url !== followedUrl;

            if (newPage) {
                forgetPage();
                followedUrl = url;
            }

            if (userStates && (newPage || userStates !== previousUserStates)) {
                absorbUserStates(userStates);
            }

            if (creatorStates && (newPage || creatorStates !== previousCreatorStates)) {
                absorbCreatorStates(creatorStates);
            }
        },
        { immediate: true, flush: 'sync' },
    );
}
