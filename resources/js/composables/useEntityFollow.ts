import { useCreatorActions, type Creator } from '@/composables/useCreatorActions';
import { useCreatorState } from '@/composables/useCreatorState';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, toValue, type ComputedRef, type MaybeRefOrGetter, type Ref } from 'vue';

/**
 * The bell on a person or company card — the creator twin of
 * `useMediaActions().setFollow`. Creators have no tracking matrix behind them
 * (no plays, no backlog), so their bell is a plain boolean: on or off, never
 * muted.
 */
export function useEntityFollow(target: MaybeRefOrGetter<Creator>): {
    followed: ComputedRef<boolean>;
    busy: Ref<boolean>;
    label: ComputedRef<string>;
    toggle: () => Promise<void>;
} {
    const busy = ref(false);
    const { toggleFollow } = useCreatorActions();

    const state = useCreatorState(target);
    const followed = computed(() => state.value.followed);

    // Names the action, not the state the button is already in — the same rule
    // the list button follows, since it is the same control strip.
    const label = computed(() => (followed.value ? trans('common.stop_following') : trans('common.follow_releases')));

    async function toggle(): Promise<void> {
        busy.value = true;

        try {
            await toggleFollow(toValue(target));
        } finally {
            busy.value = false;
        }
    }

    return { followed, busy, label, toggle };
}
