<script setup lang="ts">
import ClampedText from '@/components/ClampedText.vue';
import CompanyCatalogue from '@/components/creators/CompanyCatalogue.vue';
import CreatorActions from '@/components/creators/CreatorActions.vue';
import PersonWork from '@/components/creators/PersonWork.vue';
import DetailsModal from '@/components/media/DetailsModal.vue';
import ExternalLinks from '@/components/media/ExternalLinks.vue';
import LibraryFallbackNotice from '@/components/media/LibraryFallbackNotice.vue';
import PhotosSection from '@/components/media/PhotosSection.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Creator } from '@/lib/creators';
import { departmentLabel } from '@/lib/departments';
import { countryName, formatCalendarDate, todayDateKey, yearsBetween } from '@/lib/format';
import { mediaImage, mediaImageSrcSet } from '@/lib/images';
import type { CompanyDetails, DetailRow, PersonDetails } from '@/types/media';
import { Head, Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Building2, Tv, User } from '@lucide/vue';
import { computed } from 'vue';

// The page of a person, a studio or publisher, or a TV network: one layout —
// the portrait or logo, the facts, the actions on the right — with the work
// below in the shape that kind of creator has.
const props = defineProps<
    {
        provider: string;
        /** The provider did not answer; this page was rebuilt from the local library. */
        fromLibrary?: boolean;
    } & ({ kind: 'person'; creator: PersonDetails } | { kind: 'company' | 'network'; creator: CompanyDetails })
>();

const creator = computed<Creator>(() => ({ kind: props.kind, provider: props.provider, ref: props.creator.ref }));

const person = computed(() => (props.kind === 'person' ? props.creator : null));
const company = computed(() => (props.kind === 'person' ? null : props.creator));

const portrait = computed(() => (person.value ? mediaImage(person.value.profilePath, 'w500') : null));
const logo = computed(() => (company.value ? mediaImage(company.value.logoPath, 'w500') : null));

const age = computed(() => {
    const birthday = person.value?.birthday;

    return birthday ? yearsBetween(birthday, person.value?.deathday ?? todayDateKey()) : null;
});

/** A date of a life, with the age beside it where one is given. */
function lifeDate(date: string | null, years: number | null): string | null {
    const shown = formatCalendarDate(date);

    return shown !== null && years !== null ? trans('media.date_with_age', { date: shown, age: String(years) }) : shown;
}

// The "i" surface every entity has, carrying the creator's own Refresh.
const detailRows = computed<DetailRow[]>(() => {
    if (person.value) {
        return [
            { label: trans('media.also_known_as'), value: person.value.alsoKnownAs.length ? person.value.alsoKnownAs.join(', ') : null },
            { label: trans('media.place_of_birth'), value: person.value.placeOfBirth },
            { label: trans('media.origin'), value: person.value.origin },
        ];
    }

    const facts = company.value;

    return [
        { label: trans('media.founded'), value: facts?.founded ? formatCalendarDate(facts.founded) : null },
        { label: trans('media.country'), value: facts?.country ? countryName(facts.country) : null },
        { label: trans('media.parent_company'), value: facts?.parentName ?? null },
    ];
});
</script>

<template>
    <AppLayout>
        <Head :title="props.creator.name" />

        <div class="max-w-content mx-auto w-full flex-1 space-y-12 px-4 pt-8 pb-20 md:px-10">
            <LibraryFallbackNotice :show="fromLibrary" />

            <div class="flex flex-col items-start gap-8 md:flex-row">
                <div class="w-55 shrink-0 space-y-4">
                    <div v-if="person" class="bg-surface shadow-scrim/60 h-82.5 w-55 overflow-hidden rounded-2xl shadow-2xl">
                        <img
                            v-if="portrait"
                            :src="portrait"
                            :srcset="mediaImageSrcSet(person.profilePath, [185, 342, 500])"
                            sizes="220px"
                            :alt="person.name"
                            width="500"
                            height="750"
                            loading="lazy"
                            class="h-full w-full object-cover"
                        />
                        <div v-else class="from-surface-raised to-line flex h-full w-full items-center justify-center bg-gradient-to-br">
                            <User class="text-ink-muted size-16" />
                        </div>
                    </div>
                    <!-- Provider logos are drawn for a light background, so the
                         tile goes white when one is present (dark for the icon fallback). -->
                    <div
                        v-else
                        class="border-line-subtle shadow-scrim/40 flex h-40 w-55 items-center justify-center overflow-hidden rounded-2xl border p-6 shadow-2xl"
                        :class="logo ? 'bg-paper' : 'bg-surface'"
                    >
                        <img
                            v-if="logo"
                            :src="logo"
                            :alt="props.creator.name"
                            width="500"
                            height="500"
                            loading="lazy"
                            class="max-h-full max-w-full object-contain"
                        />
                        <component :is="kind === 'network' ? Tv : Building2" v-else class="text-ink-muted size-16" />
                    </div>

                    <!-- On a phone, one column: the actions live under the
                         portrait, like the mobile action blocks on title pages. -->
                    <CreatorActions :creator="creator" :name="props.creator.name" class="md:hidden" />
                </div>

                <div class="min-w-0 flex-1 pt-2">
                    <!-- Two columns, no third: the actions lead the right
                         one, never under the portrait. -->
                    <CreatorActions :creator="creator" :name="props.creator.name" class="mb-6 hidden md:block lg:hidden" />

                    <!-- The details button sits beside the heading, not in it: a
                         screen reader would read its label as part of the title. -->
                    <div class="mb-4 flex flex-wrap items-center gap-3">
                        <h1 class="text-hero m-0 flex flex-wrap items-center gap-3 leading-tight font-semibold">
                            <span>{{ props.creator.name }}</span>
                        </h1>
                        <DetailsModal
                            :title="trans('media.details')"
                            :rows="detailRows"
                            :refresh="{ url: route(`${kind}.refresh`, { provider, ref: props.creator.ref }) }"
                        />
                    </div>

                    <div v-if="person" class="mb-6 grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-3">
                        <div v-if="person.knownForDepartment">
                            <div class="text-ink-muted mb-1 text-sm font-bold">{{ trans('media.known_for') }}</div>
                            <div class="text-ink text-base font-semibold">{{ departmentLabel(person.knownForDepartment) }}</div>
                        </div>
                        <div v-if="person.birthday">
                            <div class="text-ink-muted mb-1 text-sm font-bold">{{ trans('media.born') }}</div>
                            <div class="text-ink text-base font-semibold">
                                {{ lifeDate(person.birthday, person.deathday ? null : age) }}
                            </div>
                        </div>
                        <div v-if="person.deathday">
                            <div class="text-ink-muted mb-1 text-sm font-bold">{{ trans('media.died') }}</div>
                            <div class="text-ink text-base font-semibold">
                                {{ lifeDate(person.deathday, age) }}
                            </div>
                        </div>
                    </div>
                    <div
                        v-else-if="company && (company.founded || company.country || company.parentName)"
                        class="mb-6 grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-3"
                    >
                        <div v-if="company.founded">
                            <div class="text-ink-muted mb-1 text-sm font-bold">{{ trans('media.founded') }}</div>
                            <div class="text-ink text-base font-semibold">{{ formatCalendarDate(company.founded) }}</div>
                        </div>
                        <div v-if="company.country">
                            <div class="text-ink-muted mb-1 text-sm font-bold">{{ trans('media.country') }}</div>
                            <div class="text-ink text-base font-semibold">{{ countryName(company.country) }}</div>
                        </div>
                        <div v-if="company.parentName">
                            <div class="text-ink-muted mb-1 text-sm font-bold">{{ trans('media.parent_company') }}</div>
                            <Link
                                v-if="company.parentRef"
                                :href="route('company.details', { provider, ref: company.parentRef })"
                                class="text-brand-soft text-base font-semibold"
                            >
                                {{ company.parentName }}
                            </Link>
                            <div v-else class="text-ink text-base font-semibold">{{ company.parentName }}</div>
                        </div>
                    </div>

                    <ExternalLinks v-if="Object.keys(props.creator.externalLinks).length" :links="props.creator.externalLinks" class="mb-6" />

                    <PhotosSection
                        v-if="person"
                        :backdrops="[]"
                        :posters="person.photos"
                        :posters-label="trans('media.photos')"
                        trigger="link"
                        class="mb-6"
                    />

                    <ClampedText v-if="person?.biography" :text="person.biography" />
                    <ClampedText v-else-if="company?.description" :text="company.description" />
                </div>

                <!-- The actions on the right, as on title pages: lists, then the bell. -->
                <div class="hidden w-72 shrink-0 lg:block">
                    <div class="sticky top-24 space-y-5">
                        <CreatorActions :creator="creator" :name="props.creator.name" />
                    </div>
                </div>
            </div>

            <PersonWork v-if="person" :person="person" />
            <CompanyCatalogue v-else-if="company && kind !== 'person'" :provider="provider" :kind="kind" :company="company" />
        </div>
    </AppLayout>
</template>
