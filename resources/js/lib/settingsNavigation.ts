import type { NavEntry } from '@/lib/navigation';
import { identityProviders, passwordLoginEnabled, viewerIsAdmin } from '@/lib/pageContext';
import type { NonEmpty } from '@/types';
import {
    Braces,
    Calendar,
    Cog,
    Download,
    KeyRound,
    Link2,
    ListChecks,
    Plug,
    Send,
    Server,
    SlidersHorizontal,
    TriangleAlert,
    Upload,
    UserRound,
    Webhook,
} from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';

/** The settings sidebar, in its order. */
export const SETTINGS_NAV: NonEmpty<NavEntry> = [
    {
        id: 'settings.profile',
        route: 'settings.profile.edit',
        label: () => trans('common.profile'),
        icon: UserRound,
        keywords: () => trans('palette.keywords_settings_profile'),
    },
    {
        id: 'settings.password',
        route: 'settings.password.edit',
        label: () => trans('common.password'),
        icon: KeyRound,
        keywords: () => trans('palette.keywords_settings_password'),
        // Gone where identity providers are the only way in.
        exists: passwordLoginEnabled,
    },
    {
        id: 'settings.identities',
        route: 'settings.identities.index',
        label: () => trans('common.connected_accounts'),
        icon: Link2,
        keywords: () => trans('palette.keywords_settings_identities'),
        exists: () => identityProviders().length > 0,
    },
    {
        id: 'settings.preferences',
        route: 'settings.preferences.edit',
        label: () => trans('common.preferences'),
        icon: SlidersHorizontal,
        keywords: () => trans('palette.keywords_settings_preferences'),
    },
    {
        id: 'settings.tracking',
        route: 'settings.tracking.edit',
        label: () => trans('common.tracking'),
        icon: ListChecks,
        keywords: () => trans('palette.keywords_settings_tracking'),
    },
    {
        id: 'settings.channels',
        route: 'settings.channels.index',
        label: () => trans('common.notifications'),
        icon: Send,
        keywords: () => trans('palette.keywords_settings_channels'),
    },
    {
        id: 'settings.calendar',
        route: 'settings.calendar-subscription.edit',
        label: () => trans('settings.calendar_subscription'),
        icon: Calendar,
        keywords: () => trans('palette.keywords_settings_calendar'),
    },
    {
        id: 'settings.integrations',
        route: 'settings.integrations.index',
        label: () => trans('common.integrations'),
        icon: Plug,
        keywords: () => trans('palette.keywords_settings_integrations'),
    },
    {
        id: 'settings.import',
        route: 'settings.import.index',
        label: () => trans('common.import_data'),
        icon: Upload,
        keywords: () => trans('palette.keywords_settings_import'),
    },
    {
        id: 'settings.export',
        route: 'settings.export.index',
        label: () => trans('common.export_data'),
        icon: Download,
        keywords: () => trans('palette.keywords_settings_export'),
    },
    {
        id: 'settings.api',
        route: 'settings.api-tokens.index',
        label: () => trans('common.api'),
        icon: Braces,
        keywords: () => trans('palette.keywords_settings_api'),
    },
    {
        id: 'settings.webhooks',
        route: 'settings.webhooks.index',
        label: () => trans('common.webhooks'),
        icon: Webhook,
        keywords: () => trans('palette.keywords_settings_webhooks'),
    },
    {
        id: 'settings.tasks',
        route: 'settings.tasks.index',
        label: () => trans('common.tasks'),
        icon: Cog,
        keywords: () => trans('palette.keywords_settings_tasks'),
        // An admin's tasks are a tab of the Instance page.
        exists: () => !viewerIsAdmin(),
    },
    {
        id: 'settings.instance',
        route: 'settings.instance.index',
        label: () => trans('common.instance'),
        icon: Server,
        keywords: () => trans('palette.keywords_settings_instance'),
        exists: viewerIsAdmin,
    },
    {
        id: 'settings.danger',
        route: 'settings.danger.index',
        label: () => trans('common.danger_zone'),
        icon: TriangleAlert,
        keywords: () => trans('palette.keywords_settings_danger'),
        destructive: true,
    },
];

/** The settings sidebar of this instance: the places it has, in their order. */
export function settingsNav(): NavEntry[] {
    return SETTINGS_NAV.filter((entry) => entry.exists?.() ?? true);
}
