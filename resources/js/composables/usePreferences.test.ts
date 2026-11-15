import { usePreferences } from '@/composables/usePreferences';
import { userPreferences } from '@/test/factories';
import { setPageProps } from '@/test/page';
import { describe, expect, it } from 'vitest';

describe('usePreferences', () => {
    it("reads the signed-in user's settings as the server sent them, following the page", () => {
        const preferences = usePreferences();

        expect(preferences.value.spoiler_protection).toBe(true);

        setPageProps({ auth: { user: { id: 1, preferences: userPreferences({ spoiler_protection: false }) } } });

        expect(preferences.value.spoiler_protection).toBe(false);
    });
});
