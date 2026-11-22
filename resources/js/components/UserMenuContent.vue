<script setup lang="ts">
import MenuRow from '@/components/header/MenuRow.vue';
import { DropdownMenuSeparator } from '@/components/ui/dropdown-menu';
import { navHref, navTint, SETTINGS_NAV, userPanel } from '@/lib/navigation';
import type { User } from '@/types';
import { trans } from 'laravel-vue-i18n';
import { LogOut, Settings } from '@lucide/vue';
import { DropdownMenuItem } from 'radix-vue';

interface Props {
    user: User;
}

const props = defineProps<Props>();

// The library's panels live in the main menu; here is the account: your page and settings, which open on their first page.
const profile = userPanel('show');
const settingsHref = navHref(SETTINGS_NAV[0]);
</script>

<template>
    <!-- The menu's content is teleported: the rows take the account's colour here, not from the header.
         Signing out is no place to go, so it wears the danger colour. -->
    <div :style="navTint('library')">
        <DropdownMenuItem as-child>
            <MenuRow
                :href="navHref(profile, props.user.username)"
                :icon="profile.icon"
                :label="profile.label()"
                :description="profile.description?.()"
            />
        </DropdownMenuItem>
        <DropdownMenuItem as-child>
            <MenuRow :href="settingsHref" :icon="Settings" :label="trans('common.settings')" :description="trans('app.nav_settings_description')" />
        </DropdownMenuItem>
        <DropdownMenuSeparator class="bg-line mx-0 my-2" />
        <DropdownMenuItem as-child>
            <MenuRow :href="route('logout')" method="post" as="button" :icon="LogOut" :label="trans('app.sign_out')" :style="navTint('danger')" />
        </DropdownMenuItem>
    </div>
</template>
