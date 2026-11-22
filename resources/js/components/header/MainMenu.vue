<script setup lang="ts">
import MenuRow from '@/components/MenuRow.vue';
import { activeMenuWord, DASHBOARD, groupItemHref, NAV_GROUPS, navHref } from '@/lib/navigation';
import { signedInUser } from '@/lib/pageContext';
import { NAV_WORD_CLASS, navWordState, rowTint } from '@/lib/ui';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import {
    NavigationMenuContent,
    NavigationMenuItem,
    NavigationMenuLink,
    NavigationMenuList,
    NavigationMenuRoot,
    NavigationMenuTrigger,
} from 'radix-vue';
import { computed, ref } from 'vue';

/**
 * The bar's words: Dashboard, then a group per question (what you have, what
 * to find, what is coming), each opening a panel of its places on hover, click
 * or keyboard. The word of the page you are on is lit; the rows in a panel are
 * not, so a panel always reads the same.
 */

const page = usePage();
const viewer = computed(() => signedInUser());
const active = computed(() => activeMenuWord(page.url, viewer.value.username));

// Which group is open ('' for none): the arrow under a word shows with its panel.
const open = ref('');
</script>

<template>
    <NavigationMenuRoot v-model="open" :delay-duration="80" class="relative">
        <NavigationMenuList class="flex items-center gap-2">
            <NavigationMenuItem>
                <NavigationMenuLink as-child :active="active === 'dashboard'">
                    <Link :href="navHref(DASHBOARD)" :class="[NAV_WORD_CLASS, 'pr-4', navWordState(active === 'dashboard')]">
                        <component :is="DASHBOARD.icon" class="size-4.5" :class="active === 'dashboard' ? 'text-ink' : 'text-ink-muted'" />
                        <span>{{ DASHBOARD.label() }}</span>
                    </Link>
                </NavigationMenuLink>
            </NavigationMenuItem>

            <NavigationMenuItem v-for="group in NAV_GROUPS" :key="group.id" :value="group.id" class="relative" :style="rowTint(group.id)">
                <NavigationMenuTrigger
                    class="group/word pr-3"
                    :class="[
                        NAV_WORD_CLASS,
                        navWordState(active === group.id),
                        active === group.id ? '' : 'data-[state=open]:bg-surface data-[state=open]:text-ink',
                    ]"
                >
                    <component
                        :is="group.icon"
                        class="size-4.5"
                        :class="active === group.id ? 'text-(color:--tint)' : 'text-ink-muted group-data-[state=open]/word:text-(color:--tint)'"
                    />
                    <span>{{ group.label() }}</span>
                    <ChevronDown class="text-ink-muted size-4 transition-transform duration-200 group-data-[state=open]/word:rotate-180" />
                </NavigationMenuTrigger>

                <!-- Each panel hangs under its own word, so the arrow says which word opened it. -->
                <span
                    v-if="open === group.id"
                    class="border-line-strong bg-surface absolute top-full left-1/2 z-60 mt-1 size-3 -translate-x-1/2 rotate-45 border-t border-l"
                    aria-hidden="true"
                />

                <NavigationMenuContent
                    class="border-line-strong bg-surface shadow-float data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=open]:fade-in data-[state=closed]:fade-out absolute top-full left-0 z-50 mt-2.5 w-max min-w-80 rounded-xl border py-2"
                >
                    <ul>
                        <li v-for="item in group.items" :key="item.entry.id">
                            <NavigationMenuLink as-child>
                                <MenuRow
                                    :href="groupItemHref(item, viewer.username)"
                                    :icon="item.entry.icon"
                                    :label="item.entry.label()"
                                    :description="item.entry.description?.()"
                                />
                            </NavigationMenuLink>
                        </li>
                    </ul>
                </NavigationMenuContent>
            </NavigationMenuItem>
        </NavigationMenuList>
    </NavigationMenuRoot>
</template>
