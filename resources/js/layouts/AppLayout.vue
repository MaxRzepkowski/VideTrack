<script lang="ts">
import { deferredComponent } from '@/lib/deferred';

// The modals and the palette open on a click, never with the page: their code
// is left out of what every page loads and fetched once, while the browser
// idles, for the whole visit to the app.
const CommandPalette = deferredComponent(() => import('@/components/command/CommandPalette.vue'));
const WatchedModal = deferredComponent(() => import('@/components/media/WatchedModal.vue'));
const RateModal = deferredComponent(() => import('@/components/media/RateModal.vue'));
const ListsModal = deferredComponent(() => import('@/components/media/ListsModal.vue'));
const CreatorListsModal = deferredComponent(() => import('@/components/media/CreatorListsModal.vue'));
</script>

<script setup lang="ts">
import AppFooter from '@/components/AppFooter.vue';
import AppHeader from '@/components/AppHeader.vue';
import AppTooltip from '@/components/AppTooltip.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import LiveEventsButton from '@/components/LiveEventsButton.vue';
import Toaster from '@/components/Toaster.vue';
import { useAskedOnce } from '@/composables/useAskedOnce';
import { useCommandPalette } from '@/composables/useCommandPalette';
import { useMediaModals } from '@/composables/useMediaModals';
import { useTimezoneSync } from '@/composables/useTimezoneSync';

useTimezoneSync();

// Each mounts the first time it is asked for. The layout holds the palette's
// shortcut, which must work before the palette itself is here.
const { modals } = useMediaModals();
const { open: paletteOpen } = useCommandPalette();

const paletteAsked = useAskedOnce(() => paletteOpen.value);
const watchedAsked = useAskedOnce(() => modals.watched !== null);
const rateAsked = useAskedOnce(() => modals.rate !== null);
const listsAsked = useAskedOnce(() => modals.lists !== null);
const creatorListsAsked = useAskedOnce(() => modals.creatorLists !== null);
</script>

<template>
    <div class="bg-surface-base text-ink flex min-h-screen flex-col">
        <AppHeader />
        <main class="flex flex-1 flex-col">
            <slot />
        </main>
        <AppFooter />

        <CommandPalette v-if="paletteAsked" />
        <WatchedModal v-if="watchedAsked" />
        <RateModal v-if="rateAsked" />
        <ListsModal v-if="listsAsked" />
        <CreatorListsModal v-if="creatorListsAsked" />
        <ConfirmDialog />
        <Toaster />
        <!-- A showcase on the air: the button switches itself by the events' times. -->
        <LiveEventsButton />
        <!-- One bubble for the whole app; triggers opt in with `v-tooltip`. -->
        <AppTooltip />
    </div>
</template>
