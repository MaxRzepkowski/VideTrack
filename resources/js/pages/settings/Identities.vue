<script setup lang="ts">
import BrandGlyph from '@/components/BrandGlyph.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { confirm } from '@/composables/useConfirm';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { SETTINGS_CARD_CLASS } from '@/lib/ui';
import type { SharedData } from '@/types';
import type { IdentityProviderOption, LinkedIdentity } from '@/types/generated';
import { Head, router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

const props = defineProps<{
    identities: LinkedIdentity[];
}>();

const page = usePage<SharedData>();

// Every provider the instance offers, with the identity connected there, if any.
const rows = computed(() =>
    page.props.identityProviders.map((provider) => ({
        provider,
        identity: props.identities.find((identity) => identity.provider === provider.code) ?? null,
    })),
);

function status(identity: LinkedIdentity | null): string {
    if (identity === null) {
        return trans('settings.identity_not_connected');
    }

    return identity.email === null ? trans('settings.identity_connected') : trans('settings.identity_connected_as', { email: identity.email });
}

async function disconnect(provider: IdentityProviderOption, identity: LinkedIdentity): Promise<void> {
    const confirmed = await confirm({
        message: trans('settings.disconnect_identity', { provider: provider.label }),
        confirmLabel: trans('common.disconnect'),
    });

    if (!confirmed) {
        return;
    }

    router.delete(route('settings.identities.destroy', { identity: identity.id }), { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="trans('common.connected_accounts')" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall :title="trans('common.connected_accounts')" :description="trans('settings.connected_accounts_description')" />

                <InputError :message="page.props.errors.identity" />

                <ul class="space-y-3">
                    <li v-for="row in rows" :key="row.provider.code" :class="[SETTINGS_CARD_CLASS, 'flex items-center gap-3']">
                        <BrandGlyph :code="row.provider.glyph" class="text-ink-muted size-6 shrink-0" />

                        <div class="min-w-0 flex-1">
                            <div class="text-ink text-base font-semibold">{{ row.provider.label }}</div>
                            <p class="text-ink-muted mt-0.5 truncate text-sm">{{ status(row.identity) }}</p>
                        </div>

                        <Button v-if="row.identity" variant="secondary" size="sm" @click="disconnect(row.provider, row.identity)">
                            {{ trans('common.disconnect') }}
                        </Button>
                        <!-- A plain link: connecting is the trip to the provider and back. -->
                        <Button v-else variant="outline" size="sm" as-child>
                            <a :href="route('identities.redirect', { provider: row.provider.code })">{{ trans('common.connect') }}</a>
                        </Button>
                    </li>
                </ul>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
