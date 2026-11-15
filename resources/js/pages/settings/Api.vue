<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import MetaLine from '@/components/MetaLine.vue';
import { confirm } from '@/composables/useConfirm';
import { useCopyToClipboard } from '@/composables/useCopyToClipboard';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { formatDateTime, relativeTime } from '@/lib/format';
import { SETTINGS_FIELD_CLASS } from '@/lib/ui';
import type { ApiTokenItem } from '@/types/generated';
import { Head, router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

const props = defineProps<{
    tokens: ApiTokenItem[];
    createdToken: string | null;
}>();

const form = useForm({
    name: '',
    write: false,
    expires_at: '',
});

function create(): void {
    form.post(route('settings.api-tokens.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

async function revoke(token: ApiTokenItem): Promise<void> {
    const confirmed = await confirm({
        message: trans('integrations.revoke_name_anything_using_this_token', { name: token.name }),
        confirmLabel: trans('integrations.revoke'),
    });

    if (!confirmed) {
        return;
    }

    router.delete(route('settings.api-tokens.destroy', { token: token.id }), { preserveScroll: true });
}

const { copied, copy } = useCopyToClipboard(trans('integrations.could_not_copy_the_token'));

async function copyToken(): Promise<void> {
    if (props.createdToken === null) {
        return;
    }

    await copy(props.createdToken);
}

function abilityLabel(abilities: string[]): string {
    return abilities.includes('write') ? trans('integrations.read_and_write') : trans('integrations.read_only');
}
</script>

<template>
    <AppLayout>
        <Head :title="trans('common.api')" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall :title="trans('common.api')" :description="trans('integrations.access_tokens_for_your_own_scripts')" />

                <a :href="route('scramble.docs.ui')" class="text-brand-soft inline-block text-base font-medium hover:underline">
                    {{ trans('integrations.api_documentation') }}
                </a>

                <div v-if="props.createdToken" class="border-brand bg-surface rounded-xl border px-5 py-4">
                    <div class="text-ink text-base font-semibold">{{ trans('integrations.your_new_token') }}</div>
                    <p class="text-ink-muted mt-0.5 mb-3 text-sm">
                        {{ trans('integrations.copy_it_now_this_is_the') }}
                    </p>
                    <div class="flex gap-2">
                        <label for="created-token" class="sr-only">{{ trans('integrations.your_new_token') }}</label>
                        <input
                            id="created-token"
                            readonly
                            :value="props.createdToken"
                            class="border-line-subtle bg-surface-base text-ink-secondary focus:border-brand min-w-0 flex-1 rounded-md border px-3 py-2 font-mono text-sm focus:outline-none"
                            @focus="($event.target as HTMLInputElement).select()"
                        />
                        <button
                            type="button"
                            class="border-line-subtle bg-surface-base text-ink hover:border-brand shrink-0 rounded-md border px-4 py-2 text-sm font-medium"
                            @click="copyToken"
                        >
                            {{ copied ? trans('common.copied') : trans('common.copy') }}
                        </button>
                    </div>
                </div>

                <div class="border-line-subtle bg-surface rounded-xl border px-5 py-4">
                    <div class="text-ink text-base font-semibold">{{ trans('integrations.new_token') }}</div>
                    <p class="text-ink-muted mt-0.5 mb-3 text-sm">
                        {{ trans('integrations.a_token_reads_your_library_through') }}
                    </p>

                    <form class="space-y-4" @submit.prevent="create">
                        <div>
                            <label for="token-name" class="text-ink mb-1 block text-base font-semibold">{{ trans('common.name') }}</label>
                            <input
                                id="token-name"
                                v-model="form.name"
                                type="text"
                                maxlength="60"
                                :placeholder="trans('integrations.laptop_script')"
                                :class="[SETTINGS_FIELD_CLASS, 'block w-full']"
                            />
                            <p v-if="form.errors.name" class="text-danger mt-1 text-sm">{{ form.errors.name }}</p>
                        </div>

                        <div>
                            <label for="token-expires" class="text-ink mb-1 block text-base font-semibold">{{
                                trans('integrations.expires_on_optional')
                            }}</label>
                            <input id="token-expires" v-model="form.expires_at" type="date" :class="[SETTINGS_FIELD_CLASS, 'block w-full']" />
                            <p v-if="form.errors.expires_at" class="text-danger mt-1 text-sm">{{ form.errors.expires_at }}</p>
                        </div>

                        <label class="flex items-start gap-2">
                            <input v-model="form.write" type="checkbox" class="border-line-subtle bg-surface-base mt-1 size-4 rounded border" />
                            <span class="text-ink text-sm">
                                {{ trans('integrations.allow_writing') }}
                                <span class="text-ink-muted block">{{
                                    trans('integrations.marking_watched_rating_statuses_progress_favorites')
                                }}</span>
                            </span>
                        </label>

                        <button
                            type="submit"
                            class="bg-brand hover:bg-brand/90 text-on-accent inline-flex items-center rounded-md px-4 py-2 text-sm font-semibold disabled:opacity-50"
                            :disabled="form.processing"
                        >
                            {{ trans('integrations.create_token') }}
                        </button>
                    </form>
                </div>

                <div class="border-line-subtle bg-surface rounded-xl border px-5 py-4">
                    <div class="text-ink text-base font-semibold">{{ trans('integrations.your_tokens') }}</div>

                    <p v-if="props.tokens.length === 0" class="text-ink-muted mt-0.5 text-sm">
                        {{ trans('integrations.no_tokens_yet_create_one_to') }}
                    </p>

                    <ul v-else class="divide-line-subtle mt-3 divide-y">
                        <li v-for="token in props.tokens" :key="token.id" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-3">
                            <div class="min-w-0">
                                <div class="text-ink text-sm font-medium">{{ token.name }}</div>
                                <MetaLine
                                    class="text-ink-muted mt-0.5 text-sm"
                                    :parts="[
                                        abilityLabel(token.abilities),
                                        token.lastUsedAt
                                            ? trans('integrations.last_used_time', { time: relativeTime(token.lastUsedAt) ?? '' })
                                            : trans('integrations.never_used'),
                                        token.expiresAt
                                            ? trans('integrations.expires_date', { date: formatDateTime(token.expiresAt) ?? '' })
                                            : undefined,
                                    ]"
                                />
                            </div>
                            <button type="button" class="text-danger shrink-0 text-sm font-medium hover:underline" @click="revoke(token)">
                                {{ trans('integrations.revoke') }}
                            </button>
                        </li>
                    </ul>
                </div>

                <p class="text-ink-muted text-sm">
                    {{ trans('integrations.send_the_token_as_an_authorization') }}
                    <code class="text-ink-secondary font-mono">Authorization: Bearer &lt;token&gt;</code>
                </p>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
