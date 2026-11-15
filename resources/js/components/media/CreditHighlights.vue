<script setup lang="ts">
import Hairline from '@/components/Hairline.vue';
import type { CreditPerson } from '@/lib/creators';
import { creatorHref } from '@/lib/urls';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

// The key people behind a title, inline: "Name | Role(s)" per credit, name and
// role the same size but different colors, the hairline between them. Links to
// the creator's own page when the provider exposes one (creatorHref), otherwise
// plain text. Hides when there is nobody.

const props = defineProps<{
    people: CreditPerson[];
}>();

const credits = computed(() => props.people.map((person) => ({ ...person, href: creatorHref(person) })));
</script>

<template>
    <div v-if="credits.length" class="border-line-subtle flex flex-wrap items-baseline gap-x-4 gap-y-1 border-t pt-4 text-sm">
        <span v-for="(person, i) in credits" :key="`${person.name}-${i}`" class="whitespace-nowrap">
            <Link
                v-if="person.href"
                :href="person.href"
                class="text-ink-secondary hover:text-brand-soft font-semibold transition-colors duration-150"
            >
                {{ person.name }}
            </Link>
            <span v-else class="text-ink-secondary font-semibold">{{ person.name }}</span>
            <template v-if="person.roles.length">
                <Hairline />
                <span class="text-ink-muted">{{ person.roles.join(', ') }}</span>
            </template>
        </span>
    </div>
</template>
