<script setup lang="ts">
import { DropdownMenuGroup, DropdownMenuItem, DropdownMenuSeparator } from '@/components/ui/dropdown-menu';
import { navHref, SETTINGS_NAV, userPanel } from '@/lib/navigation';
import type { User } from '@/types';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { LogOut, Settings } from '@lucide/vue';
import { computed } from 'vue';

interface Props {
    user: User;
}

const props = defineProps<Props>();

// Your own most-visited panels, then settings, which open on their first page.
const panels = computed(() => (['show', 'history', 'ratings', 'lists'] as const).map((id) => userPanel(id)));
const settingsHref = navHref(SETTINGS_NAV[0]);
</script>

<template>
    <DropdownMenuGroup>
        <DropdownMenuItem v-for="panel in panels" :key="panel.id" :as-child="true" class="focus:bg-line-subtle focus:text-ink rounded-lg text-base">
            <Link class="block w-full" :href="navHref(panel, props.user.username)" as="button">
                <component :is="panel.icon" class="mr-2 h-4 w-4" />
                {{ panel.label() }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem :as-child="true" class="focus:bg-line-subtle focus:text-ink rounded-lg text-base">
            <Link class="block w-full" :href="settingsHref" as="button">
                <Settings class="mr-2 h-4 w-4" />
                {{ trans('common.settings') }}
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator class="bg-line-subtle" />
    <DropdownMenuItem :as-child="true" class="focus:bg-line-subtle focus:text-ink rounded-lg text-base">
        <Link class="text-danger block w-full" method="post" :href="route('logout')" as="button">
            <LogOut class="mr-2 h-4 w-4" />
            {{ trans('app.sign_out') }}
        </Link>
    </DropdownMenuItem>
</template>
