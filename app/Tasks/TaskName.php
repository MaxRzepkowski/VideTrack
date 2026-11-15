<?php

namespace App\Tasks;

/**
 * The recurring tasks by name — the `task_runs` row of each, its schedule and
 * its label read the same value. An import's runs are named after their
 * source instead (`import-simkl`), since each source is a module of its own.
 */
enum TaskName: string
{
    case RefreshCatalog = 'refresh-catalog';
    case RefreshEntities = 'refresh-entities';
    case RefreshCollections = 'refresh-collections';
    case PrefetchTrending = 'prefetch-trending';
    case RefreshFollowedPeople = 'refresh-followed-people';
    case RefreshFollowedCompanies = 'refresh-followed-companies';
    case NotifyReleases = 'notify-releases';
    case DiscordDigest = 'discord-digest';
    case AutoImport = 'auto-import';
    case Cleanup = 'cleanup';
    case ProjectAiredEpisodes = 'project-aired-episodes';
    case RefreshSuggestionSets = 'refresh-suggestion-sets';
    case RefreshRelations = 'refresh-relations';
    case RefreshEvents = 'refresh-events';

    /** What the Tasks page calls it, in the request's language. */
    public function label(): string
    {
        return match ($this) {
            self::RefreshCatalog => __('tasks.refresh_saved_titles'),
            self::RefreshEntities => __('tasks.refresh_people_and_companies'),
            self::RefreshCollections => __('tasks.refresh_collections'),
            self::PrefetchTrending => __('tasks.prefetch_trending'),
            self::RefreshFollowedPeople => __('tasks.refresh_followed_people'),
            self::RefreshFollowedCompanies => __('tasks.refresh_followed_companies'),
            self::NotifyReleases => __('tasks.send_release_notifications'),
            self::DiscordDigest => __('tasks.send_discord_digests'),
            self::AutoImport => __('tasks.start_automatic_imports'),
            self::Cleanup => __('tasks.clean_up_expired_data'),
            self::ProjectAiredEpisodes => __('tasks.count_newly_aired_episodes'),
            self::RefreshSuggestionSets => __('tasks.refresh_suggestion_sets'),
            self::RefreshRelations => __('tasks.refresh_relations'),
            self::RefreshEvents => __('tasks.refresh_events'),
        };
    }
}
