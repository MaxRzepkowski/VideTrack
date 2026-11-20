<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import SettingsCard from '@/components/settings/SettingsCard.vue';
import SettingsRow from '@/components/settings/SettingsRow.vue';
import { Button } from '@/components/ui/button';
import { confirm } from '@/composables/useConfirm';
import { useCopyToClipboard } from '@/composables/useCopyToClipboard';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { mediaTypeMeta, rootMediaTypes } from '@/lib/mediaTypes';
import { SETTINGS_FIELD_CLASS } from '@/lib/ui';
import { feedVariantUrl, webcalUrl } from '@/lib/urls';
import type { FeedScope, SubscriptionFeed } from '@/types/generated';
import { Head, router } from '@inertiajs/vue3';
import { Copy, Download, RefreshCw } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

const props = defineProps<{
    calendarFeed: SubscriptionFeed;
    rssFeed: SubscriptionFeed;
}>();

const offeredTypes = rootMediaTypes();

// The types go for both feeds; each feed keeps its own scope, starting on the
// one its plain address carries, so both addresses start plain.
const types = ref<string[]>([...offeredTypes]);
const calendarScope = ref<FeedScope>(props.calendarFeed.defaultScope);
const rssScope = ref<FeedScope>(props.rssFeed.defaultScope);

const scopeOptions = computed<{ value: FeedScope; label: string }[]>(() => [
    { value: 'all', label: trans('settings.calendar_feed_scope_all') },
    { value: 'premieres', label: trans('settings.calendar_feed_scope_premieres') },
]);

const calendarUrl = computed<string>(() => feedVariantUrl(props.calendarFeed, { scope: calendarScope.value, types: types.value }, offeredTypes));
const rssUrl = computed<string>(() => feedVariantUrl(props.rssFeed, { scope: rssScope.value, types: types.value }, offeredTypes));

// A feed of no type would be empty, so the last type left stays ticked.
const isLastType = (type: string): boolean => types.value.length === 1 && types.value[0] === type;

const calendarCopy = useCopyToClipboard(trans('common.could_not_copy_the_link'));
const rssCopy = useCopyToClipboard(trans('common.could_not_copy_the_link'));

async function replaceAddress(): Promise<void> {
    const confirmed = await confirm({
        message: trans('settings.replace_calendar_address_confirm'),
        confirmLabel: trans('settings.replace_calendar_address'),
    });

    if (!confirmed) {
        return;
    }

    // The page comes back with the new addresses; the variant chosen stays.
    router.post(route('settings.calendar-token.update'), {}, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="trans('settings.calendar_subscription')" />

        <SettingsLayout>
            <div class="space-y-8">
                <HeadingSmall :title="trans('settings.calendar_subscription')" :description="trans('settings.calendar_subscription_addresses')" />

                <SettingsCard>
                    <SettingsRow :title="trans('settings.calendar_feed_types')">
                        <fieldset class="flex flex-wrap gap-2">
                            <legend class="sr-only">{{ trans('settings.calendar_feed_types') }}</legend>
                            <label
                                v-for="type in offeredTypes"
                                :key="type"
                                class="border-line-subtle has-checked:border-brand has-checked:bg-surface-raised text-ink flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium has-disabled:cursor-default"
                            >
                                <input
                                    v-model="types"
                                    type="checkbox"
                                    :value="type"
                                    :disabled="isLastType(type)"
                                    class="border-line-subtle bg-surface-base accent-brand size-4 rounded border"
                                />
                                <component
                                    :is="mediaTypeMeta(type).icon"
                                    class="size-4"
                                    :style="{ color: mediaTypeMeta(type).color }"
                                    aria-hidden="true"
                                />
                                {{ mediaTypeMeta(type).plural() }}
                            </label>
                        </fieldset>
                    </SettingsRow>
                </SettingsCard>

                <SettingsCard :title="trans('settings.calendar_feed_ical')">
                    <SettingsRow :title="trans('settings.calendar_feed_scope')" for="calendar-feed-scope">
                        <select id="calendar-feed-scope" v-model="calendarScope" :class="[SETTINGS_FIELD_CLASS, 'block w-full']">
                            <option v-for="option in scopeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </select>
                    </SettingsRow>
                    <SettingsRow :title="trans('settings.feed_address')" for="calendar-feed-url">
                        <div class="flex gap-2">
                            <input
                                id="calendar-feed-url"
                                readonly
                                :value="calendarUrl"
                                :class="[SETTINGS_FIELD_CLASS, 'text-ink-secondary min-w-0 flex-1']"
                                @focus="($event.target as HTMLInputElement).select()"
                            />
                            <Button type="button" variant="outline" class="h-auto shrink-0" @click="calendarCopy.copy(calendarUrl)">
                                <Copy class="size-4" aria-hidden="true" />
                                {{ calendarCopy.copied.value ? trans('common.copied') : trans('common.copy') }}
                            </Button>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <Button class="h-10 py-2" as-child>
                                <a :href="webcalUrl(calendarUrl)">{{ trans('common.subscribe') }}</a>
                            </Button>
                            <Button variant="outline" class="h-10 py-2" as-child>
                                <a :href="calendarUrl">
                                    <Download class="size-4" aria-hidden="true" />
                                    {{ trans('common.download_ics') }}
                                </a>
                            </Button>
                        </div>
                    </SettingsRow>
                </SettingsCard>

                <SettingsCard :title="trans('common.rss_feed')" :description="trans('settings.calendar_feed_rss_hint')">
                    <SettingsRow :title="trans('settings.calendar_feed_scope')" for="calendar-rss-scope">
                        <select id="calendar-rss-scope" v-model="rssScope" :class="[SETTINGS_FIELD_CLASS, 'block w-full']">
                            <option v-for="option in scopeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </select>
                    </SettingsRow>
                    <SettingsRow :title="trans('settings.feed_address')" for="calendar-rss-url">
                        <div class="flex gap-2">
                            <input
                                id="calendar-rss-url"
                                readonly
                                :value="rssUrl"
                                :class="[SETTINGS_FIELD_CLASS, 'text-ink-secondary min-w-0 flex-1']"
                                @focus="($event.target as HTMLInputElement).select()"
                            />
                            <Button type="button" variant="outline" class="h-auto shrink-0" @click="rssCopy.copy(rssUrl)">
                                <Copy class="size-4" aria-hidden="true" />
                                {{ rssCopy.copied.value ? trans('common.copied') : trans('common.copy') }}
                            </Button>
                        </div>
                    </SettingsRow>
                </SettingsCard>

                <SettingsCard>
                    <SettingsRow
                        :title="trans('settings.replace_calendar_address')"
                        :description="trans('settings.replace_calendar_address_description')"
                    >
                        <Button type="button" variant="outline" class="text-danger hover:text-danger h-10 py-2" @click="replaceAddress">
                            <RefreshCw class="size-4" aria-hidden="true" />
                            {{ trans('settings.replace_calendar_address') }}
                        </Button>
                    </SettingsRow>
                </SettingsCard>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
