import Import from '@/pages/settings/Import.vue';
import type { ImportManifest, ImportSourceDef } from '@/types/generated';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

let answer: ImportManifest;

vi.mock('@/lib/http', () => ({
    jsonFetch: () => Promise.resolve(new Response(JSON.stringify(answer), { status: 200 })),
}));

const source: ImportSourceDef = {
    code: 'tvtime',
    label: 'TV Time',
    description: '',
    inputKind: 'file',
    accept: '.zip',
    credentials: [],
    notes: [],
    connect: null,
};

function manifest(preselected: boolean): ImportManifest {
    return {
        valid: true,
        error: null,
        categories: [
            { key: 'first', label: 'First', count: 3 },
            { key: 'second', label: 'Second', count: 1 },
            { key: 'empty', label: 'Empty', count: 0 },
        ],
        lists: [],
        supportsSelection: true,
        preselected,
    };
}

async function analyzed(preselected: boolean) {
    answer = manifest(preselected);

    const wrapper = mount(Import, {
        props: { sources: [source], maxFileMegabytes: 100 },
        global: { stubs: { AppLayout: { template: '<div><slot /></div>' }, SettingsLayout: { template: '<div><slot /></div>' } } },
    });

    const file = new File(['x'], 'export.zip');
    // As a browser tags it, so the form keeps the file itself rather than a reactive copy.
    Object.defineProperty(file, Symbol.toStringTag, { value: 'File' });
    const input = wrapper.find('input[type="file"]');
    Object.defineProperty(input.element, 'files', { value: [file] });
    await input.trigger('change');
    await flushPromises();

    return wrapper;
}

const ticked = (wrapper: Awaited<ReturnType<typeof analyzed>>) =>
    wrapper
        .findAll('label')
        .filter((label) => label.find('[role="checkbox"]').exists())
        .map((label) => [label.find('span.text-ink').text(), label.find('[role="checkbox"]').attributes('data-state')]);

describe('Import', () => {
    it('ticks every category with something in it, as most exports want', async () => {
        const wrapper = await analyzed(true);

        expect(ticked(wrapper)).toEqual([
            ['First', 'checked'],
            ['Second', 'checked'],
            ['Empty', 'unchecked'],
        ]);
        expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeUndefined();
    });

    it('ticks nothing when the user has to pick, and starts nothing until they do', async () => {
        const wrapper = await analyzed(false);

        expect(ticked(wrapper)).toEqual([
            ['First', 'unchecked'],
            ['Second', 'unchecked'],
            ['Empty', 'unchecked'],
        ]);
        expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined();
    });
});
