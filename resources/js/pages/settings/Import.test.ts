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
};

function manifest(preselected: [boolean, boolean, boolean]): ImportManifest {
    return {
        valid: true,
        error: null,
        categories: [
            { key: 'first', label: 'First', count: 3, preselected: preselected[0] },
            { key: 'second', label: 'Second', count: 1, preselected: preselected[1] },
            { key: 'empty', label: 'Empty', count: 0, preselected: preselected[2] },
        ],
        lists: [],
        supportsSelection: true,
    };
}

async function analyzed(preselected: [boolean, boolean, boolean]) {
    answer = manifest(preselected);

    const wrapper = mount(Import, {
        props: { sources: [source], maxFileMegabytes: 100, selected: null },
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
        const wrapper = await analyzed([true, true, true]);

        expect(ticked(wrapper)).toEqual([
            ['First', 'checked'],
            ['Second', 'checked'],
            ['Empty', 'unchecked'],
        ]);
        expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeUndefined();
    });

    it('leaves a category for the user to tick when the source asks', async () => {
        const wrapper = await analyzed([true, false, true]);

        expect(ticked(wrapper)).toEqual([
            ['First', 'checked'],
            ['Second', 'unchecked'],
            ['Empty', 'unchecked'],
        ]);
    });

    it('ticks nothing when the user has to pick, and starts nothing until they do', async () => {
        const wrapper = await analyzed([false, false, false]);

        expect(ticked(wrapper)).toEqual([
            ['First', 'unchecked'],
            ['Second', 'unchecked'],
            ['Empty', 'unchecked'],
        ]);
        expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined();
    });

    it('opens on the source a trip to a service came back to', () => {
        const other: ImportSourceDef = { ...source, code: 'letterboxd', label: 'Letterboxd', description: 'Films you logged on Letterboxd.' };
        const mountWith = (selected: string | null) =>
            mount(Import, {
                props: { sources: [source, other], maxFileMegabytes: 100, selected },
                global: { stubs: { AppLayout: { template: '<div><slot /></div>' }, SettingsLayout: { template: '<div><slot /></div>' } } },
            });

        expect(mountWith('letterboxd').text()).toContain('Films you logged on Letterboxd.');
        expect(mountWith(null).text()).not.toContain('Films you logged on Letterboxd.');
    });

    it('names a setup link by its text when it has one, else by its address', () => {
        const noted: ImportSourceDef = {
            ...source,
            notes: [
                { text: 'Make a key:', url: 'https://example.com/keys/new?scope=read', linkText: 'New key' },
                { text: 'Get your export at', url: 'https://example.com/export?go=now', linkText: null },
            ],
        };
        const links = mount(Import, {
            props: { sources: [noted], maxFileMegabytes: 100, selected: 'tvtime' },
            global: { stubs: { AppLayout: { template: '<div><slot /></div>' }, SettingsLayout: { template: '<div><slot /></div>' } } },
        }).findAll('a[target="_blank"]');

        expect(links.map((link) => [link.text(), link.attributes('href')])).toEqual([
            ['New key', 'https://example.com/keys/new?scope=read'],
            ['example.com/export?go=now', 'https://example.com/export?go=now'],
        ]);
    });
});
