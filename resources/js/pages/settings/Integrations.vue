<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import ServiceTile from '@/components/settings/ServiceTile.vue';
import AccountSourceCard from '@/components/settings/integrations/AccountSourceCard.vue';
import WebhookSourceCard from '@/components/settings/integrations/WebhookSourceCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { heldAccounts, integrationKey, integrationSections, type HeldAccounts } from '@/lib/integrations';
import type { ConnectionSourceData, IntegrationRef, IntegrationsPage, ScrobbleSourceData } from '@/types/integrations';
import { Head } from '@inertiajs/vue3';
import { Plug, Webhook } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

// Everything about a card comes from the two registries; a new server or
// account source shows up here without touching this file.
const props = defineProps<{ page: IntegrationsPage }>();

// What each account holds, kept here so one connected on its card moves to
// the top at once; refreshed whenever the page props change.
const held = ref<HeldAccounts>(heldAccounts(props.page));

watch(
    () => props.page,
    (page) => (held.value = heldAccounts(page)),
);

const sections = computed(() => integrationSections(props.page, held.value));

/** @view A card the page places: its ref and the source it draws. */
type Entry = { key: string; ref: IntegrationRef; name: string; webhook?: ScrobbleSourceData; account?: ConnectionSourceData };

function entry(ref: IntegrationRef): Entry | null {
    if (ref.kind === 'webhook') {
        const webhook = props.page.webhooks.find((candidate) => candidate.code === ref.code);

        return webhook ? { key: integrationKey(ref), ref, name: webhook.name, webhook } : null;
    }

    const account = props.page.accounts.find((candidate) => candidate.code === ref.code);

    return account ? { key: integrationKey(ref), ref, name: account.label, account } : null;
}

const connected = computed(() => sections.value.connected.flatMap((ref) => entry(ref) ?? []));
const available = computed(() => sections.value.available.flatMap((ref) => entry(ref) ?? []));

// The tile picked in "Add", its card under the grid; a trip to a service's
// site that came back without connecting opens that card again.
const picked = ref<string | null>(props.page.selected === null ? null : integrationKey({ kind: 'account', code: props.page.selected }));
const pickedEntry = computed(() => available.value.find((candidate) => candidate.key === picked.value) ?? null);

function reveal(key: string): void {
    void nextTick(() => document.getElementById(key)?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
}

function pick(key: string): void {
    picked.value = picked.value === key ? null : key;
    reveal('integration-picked');
}

// A card that just connected leaves the grid: follow it to the top, with its tile no longer picked.
watch(
    () => connected.value.map((candidate) => candidate.key),
    (now, before) =>
        now
            .filter((key) => !before.includes(key))
            .forEach((key) => {
                picked.value = picked.value === key ? null : picked.value;
                reveal(key);
            }),
);

onMounted(() => {
    if (props.page.selected !== null) {
        reveal(pickedEntry.value ? 'integration-picked' : integrationKey({ kind: 'account', code: props.page.selected }));
    }
});
</script>

<template>
    <AppLayout>
        <Head :title="trans('common.integrations')" />

        <SettingsLayout width="wide">
            <div class="space-y-8">
                <HeadingSmall :title="trans('common.integrations')" :description="trans('integrations.media_servers_that_report_what_you')" />

                <section v-if="connected.length" class="space-y-4">
                    <h3 class="text-ink-secondary text-base font-semibold">{{ trans('integrations.connected') }}</h3>

                    <div v-for="candidate in connected" :id="candidate.key" :key="candidate.key" class="scroll-mt-24">
                        <WebhookSourceCard v-if="candidate.webhook" :source="candidate.webhook" />
                        <AccountSourceCard
                            v-else-if="candidate.account"
                            :connection="held[candidate.account.code] ?? null"
                            :source="candidate.account"
                            @update:connection="held[candidate.ref.code] = $event"
                        />
                    </div>
                </section>

                <section v-if="available.length" class="space-y-4">
                    <h3 class="text-ink-secondary text-base font-semibold">{{ trans('integrations.add') }}</h3>

                    <ul class="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                        <li v-for="candidate in available" :key="candidate.key">
                            <ServiceTile
                                :code="candidate.ref.code"
                                :label="candidate.name"
                                :fallback="candidate.webhook ? Webhook : Plug"
                                :selected="picked === candidate.key"
                                :aria-pressed="picked === candidate.key"
                                @click="pick(candidate.key)"
                            />
                        </li>
                    </ul>

                    <div v-if="pickedEntry" id="integration-picked" class="scroll-mt-24">
                        <WebhookSourceCard v-if="pickedEntry.webhook" :source="pickedEntry.webhook" />
                        <AccountSourceCard
                            v-else-if="pickedEntry.account"
                            :key="pickedEntry.key"
                            :connection="held[pickedEntry.account.code] ?? null"
                            :source="pickedEntry.account"
                            @update:connection="held[pickedEntry.ref.code] = $event"
                        />
                    </div>
                </section>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
