<script setup lang="ts">
import AppFooter from '@/components/AppFooter.vue';
import AppHeader from '@/components/AppHeader.vue';
import AppTooltip from '@/components/AppTooltip.vue';
import CommandPalette from '@/components/command/CommandPalette.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import LiveEventsButton from '@/components/LiveEventsButton.vue';
import CreatorListsModal from '@/components/media/CreatorListsModal.vue';
import ListsModal from '@/components/media/ListsModal.vue';
import RateModal from '@/components/media/RateModal.vue';
import WatchedModal from '@/components/media/WatchedModal.vue';
import Toaster from '@/components/Toaster.vue';
import { useTimezoneSync } from '@/composables/useTimezoneSync';

useTimezoneSync();
</script>

<template>
    <div class="bg-surface-base text-ink flex min-h-screen flex-col">
        <AppHeader />
        <main class="flex flex-1 flex-col">
            <slot />
        </main>
        <AppFooter />

        <!-- Owns the cmd+K / ctrl+K listener, so it mounts wherever the app
             chrome does — one palette for every page. -->
        <CommandPalette />

        <WatchedModal />
        <RateModal />
        <ListsModal />
        <CreatorListsModal />
        <ConfirmDialog />
        <Toaster />
        <!-- A showcase on the air: the button switches itself by the events' times. -->
        <LiveEventsButton />
        <!-- One bubble for the whole app; triggers opt in with `v-tooltip`. -->
        <AppTooltip />
    </div>
</template>
