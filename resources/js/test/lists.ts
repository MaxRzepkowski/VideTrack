/**
 * The element at `index`, for a test that reads one out of a list it just
 * rendered or recorded: a missing element fails the test by name instead of
 * as a property read on `undefined` further down.
 */
export function nth<T>(list: readonly T[], index: number): T {
    const item = list[index];

    if (item === undefined) {
        throw new Error(`Expected an element at index ${index}, the list has ${list.length}.`);
    }

    return item;
}
