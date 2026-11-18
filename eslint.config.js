import prettier from 'eslint-config-prettier';
import vue from 'eslint-plugin-vue';

import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript';

export default defineConfigWithVueTs(
    vue.configs['flat/recommended'],
    vueTsConfigs.recommended,
    {
        // docs holds documentation and the API spec, not app code; coverage is the report
        // Vitest writes, and deletes, while the gate lints beside it.
        ignores: ['vendor', 'node_modules', 'public', 'bootstrap/ssr', 'resources/js/components/ui/*', 'docs', 'coverage'],
    },
    {
        rules: {
            'vue/multi-word-component-names': 'off',
            // An optional prop typed `?:` already defaults to undefined, and
            // the type makes every reader handle it.
            'vue/require-default-prop': 'off',
        },
    },
    {
        // jsonFetch() carries the XSRF token and the JSON headers; a bare
        // fetch() would repeat that boilerplate.
        files: ['resources/js/**/*.{ts,vue}'],
        ignores: ['resources/js/lib/http.ts'],
        rules: {
            'no-restricted-globals': ['error', { name: 'fetch', message: 'Use jsonFetch() from @/lib/http.' }],
        },
    },
    {
        // A visit fired from a layout or a composable repaints every page under
        // an open <select>. Background work talks to a 204 endpoint through
        // jsonFetch() instead. Three composables visit only once the user has
        // acted: useListActions after a create or delete they clicked,
        // useUnsavedChanges after they chose to leave, useTestMessage after a
        // click on "Send a test message".
        files: ['resources/js/layouts/**/*.vue', 'resources/js/composables/**/*.ts'],
        ignores: [
            'resources/js/composables/useListActions.ts',
            'resources/js/composables/useUnsavedChanges.ts',
            'resources/js/composables/useTestMessage.ts',
            'resources/js/composables/**/*.test.ts',
        ],
        rules: {
            'no-restricted-imports': [
                'error',
                {
                    paths: [
                        {
                            name: '@inertiajs/vue3',
                            importNames: ['router'],
                            message: 'No router.* in the background: jsonFetch() against a 204 endpoint.',
                        },
                    ],
                },
            ],
        },
    },
    prettier,
);
