import { onBeforeUnmount, readonly, ref, type Ref } from 'vue';

/**
 * A CSS media query as a reactive boolean, for the rare case where a breakpoint
 * has to decide *where* something renders rather than how it looks. Prefer a
 * Tailwind variant wherever a class can do the job — this exists so a slot is
 * mounted once, in one place, instead of twice with one copy hidden.
 */
export function useMediaQuery(query: string): Readonly<Ref<boolean>> {
    const list = window.matchMedia(query);
    const matches = ref(list.matches);
    const onChange = (event: MediaQueryListEvent) => (matches.value = event.matches);

    list.addEventListener('change', onChange);

    onBeforeUnmount(() => list.removeEventListener('change', onChange));

    return readonly(matches);
}
