<script setup lang="ts">
import PageHeader from '@/components/PageHeader.vue';
import { getInitials } from '@/lib/format';
import { navHref, USER_PANELS, userPanel, type UserPanel } from '@/lib/navigation';
import { NAV_WORD_CLASS, navWordState } from '@/lib/ui';
import type { ProfileData } from '@/types/media';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Lock } from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        profile: ProfileData;
        isOwner: boolean;
        /** Which panel is open — the route name without the `users.` prefix. */
        active: UserPanel;
        /**
         * How much of the identity the pill row states. `link` is the chip
         * alone — a way back to the profile. The privacy mark belongs to the
         * profile panel itself, and another panel that repeats it spends a
         * row on something the tab bar below already answers.
         */
        identity?: 'full' | 'link';
    }>(),
    { identity: 'full' },
);

// Every panel of a user hangs off the same header, so the tab bar is the same
// everywhere.
const tabs = computed(() =>
    USER_PANELS.filter((panel) => props.isOwner || !panel.ownerOnly).map((panel) => ({
        key: panel.id,
        label: panel.label(),
        icon: panel.icon,
        href: navHref(panel, props.profile.username),
    })),
);

const profileHref = computed(() => navHref(userPanel('show'), props.profile.username));

const nav = ref<HTMLElement | null>(null);

async function revealActiveTab(): Promise<void> {
    await nextTick();
    nav.value?.querySelector<HTMLElement>('[aria-current="page"]')?.scrollIntoView({ block: 'nearest', inline: 'center' });
}

onMounted(revealActiveTab);
watch(() => props.active, revealActiveTab);
</script>

<template>
    <!-- A chip-only header signs the panel rather than introducing it. The
         name and the tab bar stay two separate statements, so they keep the
         full gap between them — the same one on every panel, the profile
         included, or walking between them would nudge the tabs up and down. -->
    <PageHeader>
        <template #meta>
            <!-- Whose panel this is, as a pill on the block's top edge. -->
            <Link :href="profileHref" class="group border-line bg-surface flex h-9 items-center gap-2 rounded-full border py-1 pr-3.5 pl-1">
                <div
                    class="from-brand to-brand-strong text-initials text-on-accent flex size-7 shrink-0 items-center justify-center overflow-hidden rounded-full bg-gradient-to-br font-bold"
                >
                    <img
                        v-if="profile.avatar"
                        :src="profile.avatar"
                        :alt="profile.name"
                        width="28"
                        height="28"
                        loading="lazy"
                        class="h-full w-full object-cover"
                    />
                    <span v-else>{{ getInitials(profile.name) }}</span>
                </div>
                <span class="text-ink group-hover:text-brand-soft text-base font-semibold transition-colors duration-150">
                    {{ profile.name }}
                </span>
            </Link>
            <span
                v-if="identity === 'full' && profile.isPrivate"
                class="border-line bg-surface text-ink-muted flex h-9 items-center gap-1.5 rounded-full border px-3.5 text-base font-semibold"
            >
                <Lock class="size-3.5" :stroke-width="2.5" />
                {{ trans('common.private') }}
            </span>
        </template>

        <!-- Panels of this user, in the main menu's words: they are places to go,
             not the filter chips a panel may show below. -->
        <template #nav>
            <nav ref="nav" class="scroll-subtle -mx-4 flex flex-nowrap items-center gap-2 overflow-x-auto px-4 md:mx-0 md:px-0">
                <Link
                    v-for="tab in tabs"
                    :key="tab.key"
                    :href="tab.href"
                    :aria-current="active === tab.key ? 'page' : undefined"
                    :class="[NAV_WORD_CLASS, navWordState(active === tab.key)]"
                >
                    <component :is="tab.icon" class="size-4.5" :class="active === tab.key ? 'text-ink' : 'text-ink-muted'" />
                    {{ tab.label }}
                </Link>
            </nav>
        </template>
    </PageHeader>
</template>
