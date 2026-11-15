<script setup lang="ts">
import { RATING_TIERS } from '@/lib/ratings';

// The tier labels are the identity channel of the colour scale.
</script>

<template>
    <div class="mb-5 flex flex-wrap items-center gap-x-6 gap-y-2">
        <span v-for="tier in RATING_TIERS" :key="tier.min" class="text-ink-muted flex items-center gap-2 text-base font-semibold">
            <span class="size-3.5 rounded" :style="{ background: tier.color }"></span>
            {{ tier.label() }}
        </span>
    </div>
</template>
