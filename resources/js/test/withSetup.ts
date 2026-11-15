import { mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent, h } from 'vue';

/**
 * Runs a composable inside a mounted component, so its lifecycle hooks
 * (onMounted, onBeforeUnmount, onScopeDispose) fire as they do in the app.
 * Unmount the wrapper to see the teardown.
 */
export function withSetup<T>(composable: () => T): { result: T; wrapper: VueWrapper } {
    let result!: T;
    const wrapper = mount(
        defineComponent({
            setup() {
                result = composable();

                return () => h('div');
            },
        }),
    );

    return { result, wrapper };
}
