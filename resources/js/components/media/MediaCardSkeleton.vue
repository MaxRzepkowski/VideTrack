<template>
    <div class="border-line-subtle bg-surface xs:rounded-2xl flex flex-col overflow-hidden rounded-xl border">
        <div class="skeleton aspect-poster w-full"></div>
        <div class="border-surface-overlay bg-surface-raised border-y py-5.5"></div>
        <!-- Two caption rows, in the heights CardCaption holds — the grid must
             not jump when the real cards replace the placeholders. -->
        <div class="xs:min-h-17.5 xs:p-3 min-h-15 p-2">
            <div class="xs:h-6 flex h-5 items-center justify-center">
                <div class="skeleton h-4 w-2/3 rounded"></div>
            </div>
            <div class="xs:h-5 mt-0.5 flex h-4 items-center justify-center">
                <div class="skeleton h-3 w-1/3 rounded"></div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.skeleton {
    /* Base = raised card tone, highlight clearly lighter so the sweep reads. */
    background: linear-gradient(90deg, var(--color-surface-raised) 0%, var(--color-skeleton-shine) 50%, var(--color-surface-raised) 100%);
    background-size: 200% 100%;
    animation: shimmer 1.4s infinite linear;
}

@keyframes shimmer {
    0% {
        background-position: 150% 0;
    }
    100% {
        background-position: -150% 0;
    }
}
</style>
