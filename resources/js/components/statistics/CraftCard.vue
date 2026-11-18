<script setup lang="ts">
import { getInitials } from '@/lib/format';
import { mediaImage } from '@/lib/images';
import { mediaTypeMeta } from '@/lib/mediaTypes';
import { countTitles } from '@/lib/statistics';
import { creatorHref } from '@/lib/urls';
import type { CraftPeople, Department, PersonTally } from '@/types/generated';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

/**
 * One craft's people behind the period's titles: a portrait each (initials
 * where there is none), the name and how many titles. A person with a page
 * leads to it.
 */
defineProps<{
    craft: CraftPeople;
    type: string | null;
}>();

// Thunks, so every `trans()` stays a literal call the key checks can see.
const TITLES: Partial<Record<Department, () => string>> = {
    acting: () => trans('statistics.craft_acting'),
    directing: () => trans('statistics.craft_directing'),
    writing: () => trans('statistics.craft_writing'),
    author: () => trans('statistics.craft_author'),
};

const pageOf = (person: PersonTally): string | null => creatorHref({ kind: 'person', provider: person.provider, ref: person.ref });
</script>

<template>
    <div class="border-line-subtle bg-surface flex min-w-0 flex-col gap-5 rounded-xl border px-6 pt-6 pb-7 md:px-7">
        <h3 class="text-ink m-0 flex items-center gap-2 text-lg font-semibold">
            {{ TITLES[craft.department]?.() }}
            <component
                :is="mediaTypeMeta(code).icon"
                v-for="code in craft.types"
                :key="code"
                class="size-[18px] shrink-0"
                :style="{ color: mediaTypeMeta(code).color }"
                aria-hidden="true"
            />
        </h3>

        <ol class="m-0 grid list-none grid-cols-3 gap-3 p-0 sm:grid-cols-5">
            <li v-for="person in craft.people" :key="`${person.ref ?? person.name}`" class="min-w-0">
                <component :is="pageOf(person) ? Link : 'div'" :href="pageOf(person) ?? undefined" class="group flex flex-col gap-2">
                    <img
                        v-if="person.profilePath"
                        :src="mediaImage(person.profilePath, 'w185') ?? undefined"
                        alt=""
                        width="185"
                        height="278"
                        loading="lazy"
                        class="bg-surface-raised group-hover:ring-brand aspect-[3/4] w-full rounded-xl object-cover object-top transition-shadow duration-150 group-hover:ring-2"
                    />
                    <span
                        v-else
                        class="bg-surface-raised text-ink-muted grid aspect-[3/4] w-full place-items-center rounded-xl text-2xl font-semibold"
                        aria-hidden="true"
                    >
                        {{ getInitials(person.name) }}
                    </span>
                    <span class="flex flex-col">
                        <span class="text-ink line-clamp-2 text-sm font-medium wrap-break-word hyphens-auto group-hover:underline">{{
                            person.name
                        }}</span>
                        <span class="text-ink-muted text-sm">{{ countTitles(type, person.titles) }}</span>
                    </span>
                </component>
            </li>
        </ol>
    </div>
</template>
