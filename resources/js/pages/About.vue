<script setup lang="ts">
import SectionHeading from '@/components/SectionHeading.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { splitAtPlaceholder } from '@/lib/format';
import type { SharedData } from '@/types';
import type { DataSourceCredit } from '@/types/generated';
import { Head, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

// The sources come credited by the modules that use them, their notices
// already in the reader's language. The version and the source come shared
// with every page, for the footer.
defineProps<{
    dataSources: DataSourceCredit[];
}>();

const { version, sourceUrl } = usePage<SharedData>().props;

// @view A licence a notice links to: the app's own, or a credit's two fields put together.
type License = { name: string; url: string };

const AGPL: License = { name: 'GNU AGPL-3.0', url: 'https://www.gnu.org/licenses/agpl-3.0' };

/**
 * Translations stay plain text — no HTML in the JSON files. A sentence names
 * its licence with `:license`, and only that name carries the link.
 */
function noticeParts(notice: string, license?: License): Array<{ text: string; url?: string }> {
    const { before, after } = splitAtPlaceholder(notice, ':license');

    if (after === null || !license) {
        return [{ text: notice }];
    }

    return [{ text: before }, { text: license.name, url: license.url }, { text: after }];
}

function licenseOf(credit: DataSourceCredit): License | undefined {
    return credit.licenseName !== null && credit.licenseUrl !== null ? { name: credit.licenseName, url: credit.licenseUrl } : undefined;
}
</script>

<template>
    <AppLayout>
        <Head :title="trans('common.about')" />

        <div class="max-w-reading mx-auto w-full flex-1 px-4 py-14 md:px-10">
            <div class="flex flex-col items-center text-center">
                <img src="/logo.svg" alt="VideTrack" width="162" height="27" loading="lazy" class="h-8 w-auto" />
                <p class="text-ink-muted mt-3 text-sm">{{ trans('app.version_version', { version }) }}</p>
                <p class="text-ink-muted mt-6 max-w-lg text-base">
                    {{ trans('app.track_movies_tv_shows_games_books') }}
                </p>
                <p class="text-ink-muted mt-6 text-sm">
                    <template v-for="(part, index) in noticeParts(trans('app.videtrack_is_free_software_under_the'), AGPL)" :key="index">
                        <a v-if="part.url" :href="part.url" target="_blank" rel="noopener" class="hover:text-ink underline underline-offset-4">{{
                            part.text
                        }}</a>
                        <template v-else>{{ part.text }}</template>
                    </template>
                </p>
                <p class="text-ink-muted mt-2 text-sm">
                    <a :href="sourceUrl" target="_blank" rel="noopener" class="hover:text-ink underline underline-offset-4">{{
                        trans('app.source_code')
                    }}</a>
                </p>
                <p class="text-ink-muted mt-2 text-sm">{{ trans('app.built_with_laravel_vue_and_other') }}</p>
            </div>

            <section class="mt-10">
                <SectionHeading :title="trans('app.data_sources')" />
                <div class="space-y-4">
                    <div v-for="entry in dataSources" :key="entry.name" class="border-line-subtle bg-surface rounded-xl border px-6 py-6">
                        <a :href="entry.url" target="_blank" rel="noopener" class="text-ink hover:text-brand-soft inline-block text-base font-bold">
                            <img
                                v-if="entry.logo"
                                :src="entry.logo"
                                :alt="entry.name"
                                :width="entry.logoWidth ?? undefined"
                                :height="entry.logoHeight ?? undefined"
                                loading="lazy"
                                class="h-4 w-auto"
                            />
                            <template v-else>{{ entry.name }}</template>
                        </a>
                        <p v-for="(notice, index) in entry.notices" :key="index" class="text-ink-muted mt-2 text-sm">
                            <template v-for="(part, partIndex) in noticeParts(notice, licenseOf(entry))" :key="partIndex">
                                <a
                                    v-if="part.url"
                                    :href="part.url"
                                    target="_blank"
                                    rel="noopener"
                                    class="hover:text-ink underline underline-offset-4"
                                    >{{ part.text }}</a
                                >
                                <template v-else>{{ part.text }}</template>
                            </template>
                        </p>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
