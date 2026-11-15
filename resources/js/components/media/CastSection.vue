<script setup lang="ts" generic="T extends CastPerson">
import SectionHeading from '@/components/SectionHeading.vue';
import { Dialog, DialogContent, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { jobLabel, type CastPerson } from '@/lib/credits';
import { departmentLabel, type Department } from '@/lib/departments';
import { withCount } from '@/lib/format';
import { mediaImage } from '@/lib/images';
import { Link } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { User } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    cast: T[];
    crew: T[];
    /**
     * The line under a person, where the page has its own to say (a
     * collection: in how many films). Without it, a show's credit says in how
     * many episodes, per character in the full list.
     */
    caption?: (credit: T) => string | null;
}>();

// Modal tabs: Cast plus one tab per crew department, with counters.
const tabs = computed(() => {
    const departments = new Map<Department, T[]>();

    for (const credit of props.crew) {
        const list = departments.get(credit.department) ?? [];
        list.push(credit);
        departments.set(credit.department, list);
    }

    return [
        { key: 'Cast', label: trans('media.cast'), credits: props.cast },
        ...[...departments.entries()].map(([department, credits]) => ({ key: department, label: departmentLabel(department), credits })),
    ];
});

const activeTab = ref('Cast');
const activeCredits = computed(() => tabs.value.find((t) => t.key === activeTab.value)?.credits ?? []);

// A cast role is a character's name and stays as written; a crew role is a
// job, said in the reader's language.
function roleText(role: string | null | undefined): string | null {
    return role === null || role === undefined || activeTab.value === 'Cast' ? (role ?? null) : jobLabel(role);
}

// Episode counts come from aggregate credits (shows & seasons); movies / single
// episodes have none. A person can play several characters across seasons, each
// with its own episode count (e.g. American Horror Story).
function epi(count: number | null | undefined): string | null {
    return count ? transChoice('common.count_episode', count, { count: String(count) }) : null;
}

function captionOf(credit: T): string | null {
    return props.caption ? props.caption(credit) : epi(credit.episodeCount);
}

// The compact strip joins the character names; the modal lists each with its
// own episode count.
function roleNames(credit: T): string | null {
    if (credit.roles.length) {
        return (
            credit.roles
                .map((r) => r.role)
                .filter((r): r is string => !!r)
                .join(' / ') ||
            (credit.role ?? null)
        );
    }

    return credit.role ?? null;
}
</script>

<template>
    <section v-if="cast.length > 0">
        <Dialog>
            <SectionHeading :title="trans('media.cast')">
                <DialogTrigger as-child>
                    <button type="button" class="text-brand-soft hidden text-base font-semibold sm:inline-flex">
                        {{ trans('media.see_all_cast_crew') }} →
                    </button>
                </DialogTrigger>
            </SectionHeading>

            <!-- As many whole tiles as the width holds, never a sliver of the
                 next one: the columns share the width (130 px at least) and
                 the scroll stops on a tile's edge. -->
            <div class="cast-strip">
                <div class="cast-scroll grid snap-x snap-mandatory gap-4 overflow-x-auto pb-2">
                    <Link
                        v-for="credit in cast.slice(0, 24)"
                        :key="credit.personRef"
                        :href="route('person.details', { provider: credit.provider, ref: credit.personRef })"
                        class="group block min-w-0 snap-start"
                    >
                        <div
                            class="border-line-subtle bg-surface-raised group-hover:border-brand aspect-poster w-full overflow-hidden rounded-xl border transition-colors duration-150"
                        >
                            <img
                                v-if="credit.profilePath"
                                :src="mediaImage(credit.profilePath, 'w185') ?? undefined"
                                :alt="credit.name"
                                width="185"
                                height="278"
                                loading="lazy"
                                class="h-full w-full object-cover"
                            />
                            <div v-else class="from-surface-raised to-line flex h-full w-full items-center justify-center bg-gradient-to-br">
                                <User class="text-ink-muted size-8" />
                            </div>
                        </div>
                        <div class="text-ink mt-2 truncate text-sm font-bold">{{ credit.name }}</div>
                        <div class="text-ink-muted text-sm">{{ roleNames(credit) }}</div>
                        <div v-if="captionOf(credit)" class="text-ink-faint text-sm">{{ captionOf(credit) }}</div>
                    </Link>
                </div>
            </div>

            <DialogTrigger as-child>
                <button type="button" class="text-brand-soft mt-5 ml-auto flex w-fit text-base font-semibold sm:hidden">
                    {{ trans('media.see_all_cast_crew') }} →
                </button>
            </DialogTrigger>

            <DialogContent class="border-line-subtle bg-surface max-w-panel flex max-h-[85dvh] flex-col rounded-2xl p-4 sm:p-7">
                <DialogTitle class="shrink-0 pr-10 text-2xl font-semibold">
                    {{ trans('media.cast_crew') }}
                </DialogTitle>
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    <button
                        v-for="tab in tabs"
                        :key="tab.key"
                        type="button"
                        class="rounded-lg px-4 py-2 text-sm font-semibold transition-colors duration-150"
                        :class="activeTab === tab.key ? 'bg-brand text-on-accent' : 'bg-surface-raised text-ink-muted'"
                        :aria-pressed="activeTab === tab.key"
                        @click="activeTab = tab.key"
                    >
                        {{ withCount(tab.label, tab.credits.length) }}
                    </button>
                </div>
                <div class="overflow-y-auto">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Link
                            v-for="credit in activeCredits"
                            :key="`${credit.personRef}-${credit.role}`"
                            :href="route('person.details', { provider: credit.provider, ref: credit.personRef })"
                            class="group hover:bg-highlight/5 flex items-center gap-3 rounded-lg p-2 transition-colors duration-150"
                        >
                            <div class="border-line-subtle bg-surface-raised h-14 w-14 shrink-0 overflow-hidden rounded-full border">
                                <img
                                    v-if="credit.profilePath"
                                    :src="mediaImage(credit.profilePath, 'w185') ?? undefined"
                                    :alt="credit.name"
                                    width="185"
                                    height="278"
                                    loading="lazy"
                                    class="h-full w-full object-cover"
                                />
                                <div v-else class="from-surface-raised to-line flex h-full w-full items-center justify-center bg-gradient-to-br">
                                    <User class="text-ink-muted size-5" />
                                </div>
                            </div>
                            <div class="min-w-0">
                                <div class="text-ink truncate text-sm font-bold">{{ credit.name }}</div>
                                <!-- The episode count is not a second half of
                                     the role, so it neither shares its line
                                     nor needs a separator: it stands under
                                     it, quieter. -->
                                <template v-if="credit.roles.length">
                                    <div v-for="(r, i) in credit.roles" :key="i" class="text-ink-muted text-sm">
                                        <div>{{ roleText(r.role) }}</div>
                                        <div v-if="epi(r.episodeCount)" class="text-ink-faint">{{ epi(r.episodeCount) }}</div>
                                    </div>
                                </template>
                                <div v-else class="text-ink-muted text-sm">{{ roleText(credit.role) }}</div>
                                <div v-if="caption && caption(credit)" class="text-ink-faint text-sm">{{ caption(credit) }}</div>
                            </div>
                        </Link>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </section>
</template>

<style scoped>
/* A tile is 130 px at least with a 1rem gap: n of them fit from 146n - 16 px. */
.cast-strip {
    container-type: inline-size;
}
.cast-scroll {
    --cast-columns: 2;
    grid-auto-flow: column;
    grid-auto-columns: calc((100% - (var(--cast-columns) - 1) * 1rem) / var(--cast-columns));
}
@container (min-width: 422px) {
    .cast-scroll {
        --cast-columns: 3;
    }
}
@container (min-width: 568px) {
    .cast-scroll {
        --cast-columns: 4;
    }
}
@container (min-width: 714px) {
    .cast-scroll {
        --cast-columns: 5;
    }
}
@container (min-width: 860px) {
    .cast-scroll {
        --cast-columns: 6;
    }
}
@container (min-width: 1006px) {
    .cast-scroll {
        --cast-columns: 7;
    }
}
@container (min-width: 1152px) {
    .cast-scroll {
        --cast-columns: 8;
    }
}
@container (min-width: 1298px) {
    .cast-scroll {
        --cast-columns: 9;
    }
}
@container (min-width: 1444px) {
    .cast-scroll {
        --cast-columns: 10;
    }
}
@container (min-width: 1590px) {
    .cast-scroll {
        --cast-columns: 11;
    }
}
@container (min-width: 1736px) {
    .cast-scroll {
        --cast-columns: 12;
    }
}
.cast-scroll::-webkit-scrollbar {
    height: 8px;
}
.cast-scroll::-webkit-scrollbar-track {
    background: transparent;
}
.cast-scroll::-webkit-scrollbar-thumb {
    background: var(--color-line);
    border-radius: 4px;
}
</style>
