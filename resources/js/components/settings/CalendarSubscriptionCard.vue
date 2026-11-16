<script setup lang="ts">
import { confirm } from '@/composables/useConfirm';
import { useCopyToClipboard } from '@/composables/useCopyToClipboard';
import { mediaTypeMeta, rootMediaTypes } from '@/lib/mediaTypes';
import { SETTINGS_CARD_CLASS, SETTINGS_FIELD_CLASS } from '@/lib/ui';
import { feedVariantUrl, webcalUrl } from '@/lib/urls';
import type { FeedScope, SubscriptionFeed } from '@/types/generated';
import { router } from '@inertiajs/vue3';
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
    { value: 'all', label: trans('import.calendar_feed_scope_all') },
    { value: 'premieres', label: trans('import.calendar_feed_scope_premieres') },
]);

const calendarUrl = computed<string>(() => feedVariantUrl(props.calendarFeed, { scope: calendarScope.value, types: types.value }, offeredTypes));
const rssUrl = computed<string>(() => feedVariantUrl(props.rssFeed, { scope: rssScope.value, types: types.value }, offeredTypes));

// A feed of no type would be empty, so the last type left stays ticked.
const isLastType = (type: string): boolean => types.value.length === 1 && types.value[0] === type;

const calendarCopy = useCopyToClipboard(trans('common.could_not_copy_the_link'));
const rssCopy = useCopyToClipboard(trans('common.could_not_copy_the_link'));

async function replaceAddress(): Promise<void> {
    const confirmed = await confirm({
        message: trans('import.replace_calendar_address_confirm'),
        confirmLabel: trans('import.replace_calendar_address'),
    });

    if (!confirmed) {
        return;
    }

    // The page comes back with the new addresses; the variant chosen stays.
    router.post(route('settings.calendar-token.update'), {}, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <div :class="SETTINGS_CARD_CLASS">
        <div class="text-ink text-base font-semibold">{{ trans('import.calendar_subscription') }}</div>
        <p class="text-ink-muted mt-0.5 mb-4 text-sm">{{ trans('import.calendar_subscription_addresses') }}</p>

        <div class="flex flex-col gap-4">
            <fieldset>
                <legend class="text-ink mb-2 text-sm font-semibold">{{ trans('import.calendar_feed_types') }}</legend>
                <div class="flex flex-wrap gap-x-5 gap-y-2">
                    <label v-for="type in offeredTypes" :key="type" class="text-ink flex items-center gap-2 text-sm">
                        <input
                            v-model="types"
                            type="checkbox"
                            :value="type"
                            :disabled="isLastType(type)"
                            class="border-line-subtle bg-surface-base size-4 rounded border"
                        />
                        <component :is="mediaTypeMeta(type).icon" class="size-4" :style="{ color: mediaTypeMeta(type).color }" aria-hidden="true" />
                        {{ mediaTypeMeta(type).plural() }}
                    </label>
                </div>
            </fieldset>

            <div class="border-line-subtle border-t pt-4">
                <div class="text-ink mb-2 text-sm font-semibold">{{ trans('import.calendar_feed_ical') }}</div>
                <label for="calendar-feed-scope" class="text-ink-muted mb-1 block text-sm">{{ trans('import.calendar_feed_scope') }}</label>
                <select id="calendar-feed-scope" v-model="calendarScope" :class="[SETTINGS_FIELD_CLASS, 'mb-3']">
                    <option v-for="option in scopeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
                <label for="calendar-feed-url" class="sr-only">{{ trans('import.calendar_feed_ical') }}</label>
                <div class="flex gap-2">
                    <input
                        id="calendar-feed-url"
                        readonly
                        :value="calendarUrl"
                        :class="[SETTINGS_FIELD_CLASS, 'text-ink-secondary min-w-0 flex-1']"
                        @focus="($event.target as HTMLInputElement).select()"
                    />
                    <button
                        type="button"
                        class="border-line-subtle bg-surface-base text-ink hover:border-brand shrink-0 rounded-md border px-4 py-2 text-sm font-medium"
                        @click="calendarCopy.copy(calendarUrl)"
                    >
                        {{ calendarCopy.copied.value ? trans('common.copied') : trans('common.copy') }}
                    </button>
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <a
                        :href="webcalUrl(calendarUrl)"
                        class="bg-brand hover:bg-brand/90 text-on-accent inline-flex items-center rounded-md px-4 py-2 text-sm font-semibold"
                    >
                        {{ trans('common.subscribe') }}
                    </a>
                    <a
                        :href="calendarUrl"
                        class="border-line-subtle bg-surface-base text-ink hover:border-brand inline-flex items-center rounded-md border px-4 py-2 text-sm font-medium"
                    >
                        {{ trans('common.download_ics') }}
                    </a>
                </div>
            </div>

            <div class="border-line-subtle border-t pt-4">
                <div class="text-ink text-sm font-semibold">{{ trans('common.rss_feed') }}</div>
                <p class="text-ink-muted mb-2 text-sm">{{ trans('import.calendar_feed_rss_hint') }}</p>
                <label for="calendar-rss-scope" class="text-ink-muted mb-1 block text-sm">{{ trans('import.calendar_feed_scope') }}</label>
                <select id="calendar-rss-scope" v-model="rssScope" :class="[SETTINGS_FIELD_CLASS, 'mb-3']">
                    <option v-for="option in scopeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
                <label for="calendar-rss-url" class="sr-only">{{ trans('common.rss_feed') }}</label>
                <div class="flex gap-2">
                    <input
                        id="calendar-rss-url"
                        readonly
                        :value="rssUrl"
                        :class="[SETTINGS_FIELD_CLASS, 'text-ink-secondary min-w-0 flex-1']"
                        @focus="($event.target as HTMLInputElement).select()"
                    />
                    <button
                        type="button"
                        class="border-line-subtle bg-surface-base text-ink hover:border-brand shrink-0 rounded-md border px-4 py-2 text-sm font-medium"
                        @click="rssCopy.copy(rssUrl)"
                    >
                        {{ rssCopy.copied.value ? trans('common.copied') : trans('common.copy') }}
                    </button>
                </div>
            </div>

            <div class="border-line-subtle border-t pt-4">
                <button
                    type="button"
                    class="border-line-subtle bg-surface-base text-danger hover:border-danger rounded-md border px-4 py-2 text-sm font-medium"
                    @click="replaceAddress"
                >
                    {{ trans('import.replace_calendar_address') }}
                </button>
            </div>
        </div>
    </div>
</template>
