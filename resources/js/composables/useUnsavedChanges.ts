import { confirm } from '@/composables/useConfirm';
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { onBeforeUnmount, onMounted, watch } from 'vue';

/*
 * One guard for every draft on the page: a page may hold several forms (one
 * per webhook endpoint), and leaving asks once whichever of them is dirty.
 */
const drafts = new Set<() => boolean>();
let removeBeforeListener: (() => void) | undefined;
// Set only while the visit the user agreed to is made again: Inertia asks its
// `before` listeners synchronously inside `router.visit`, so the flag is down
// again before anything else can navigate.
let leaving = false;

function guardVisits(): () => void {
    return router.on('before', (event) => {
        const visit = event.detail.visit;

        // Saves and independent actions (such as a channel's test message) keep their
        // draft. Prefetching must never prompt just for hovering a link.
        if (leaving || visit.method !== 'get' || visit.prefetch || ![...drafts].some((isDirty) => isDirty())) {
            return;
        }

        // The app's own dialog answers later than a visit can wait, so the
        // visit stops here and is made again once the user agrees.
        event.preventDefault();

        void confirm({ message: trans('app.you_have_unsaved_changes_leave_this'), confirmLabel: trans('app.leave_page') }).then((leave) => {
            if (!leave) {
                return;
            }

            leaving = true;

            try {
                router.visit(visit.url, {
                    preserveScroll: visit.preserveScroll,
                    preserveState: visit.preserveState,
                    replace: visit.replace,
                });
            } finally {
                leaving = false;
            }
        });
    });
}

/** Protect draft settings from link navigation and closing/reloading the tab. */
export function useUnsavedChanges(isDirty: () => boolean): void {
    let stopWatching: (() => void) | undefined;

    const beforeUnload = (event: BeforeUnloadEvent): void => {
        if (isDirty()) {
            event.preventDefault();
            event.returnValue = '';
        }
    };

    onMounted(() => {
        drafts.add(isDirty);
        removeBeforeListener ??= guardVisits();

        // Only attach while dirty so clean settings pages remain eligible
        // for the browser's back/forward cache.
        stopWatching = watch(
            isDirty,
            (dirty) => {
                if (dirty) {
                    window.addEventListener('beforeunload', beforeUnload);
                } else {
                    window.removeEventListener('beforeunload', beforeUnload);
                }
            },
            { immediate: true, flush: 'sync' },
        );
    });

    onBeforeUnmount(() => {
        drafts.delete(isDirty);

        if (drafts.size === 0) {
            removeBeforeListener?.();
            removeBeforeListener = undefined;
        }

        stopWatching?.();
        window.removeEventListener('beforeunload', beforeUnload);
    });
}
