<?php

namespace App\Tasks;

use App\Catalog\Creators\CreatorTable;
use App\Jobs\CleanupExpiredData;
use App\Jobs\NotifyTodaysReleases;
use App\Jobs\PrefetchTrending;
use App\Jobs\ProjectAiredEpisodes;
use App\Jobs\QueueAutoImports;
use App\Jobs\RefreshCatalog;
use App\Jobs\RefreshCollections;
use App\Jobs\RefreshEntityMetadata;
use App\Jobs\RefreshEvents;
use App\Jobs\RefreshFollowedCreators;
use App\Jobs\RefreshRelations;
use App\Jobs\RefreshSuggestionSets;
use App\Jobs\SendDiscordDigests;

/**
 * Single source of truth for the recurring background tasks.
 * routes/console.php registers them with the scheduler; the settings "Tasks"
 * section reads the same definitions to show frequency and next run —
 * the schedule defined in routes/console.php is not visible in HTTP context.
 */
class ScheduledTasks
{
    /**
     * @return list<ScheduledTask>
     */
    public static function all(): array
    {
        return [
            // Ahead of the followed people (5:00) and today's releases (5:30),
            // so both read titles refreshed this morning.
            new ScheduledTask(
                name: TaskName::RefreshCatalog,
                cron: '0 4 * * *',
                frequency: 'common.daily',
                queue: fn () => RefreshCatalog::queueTask(),
            ),
            // Weekly: people and companies age the same way titles do (a
            // provider moving an image file leaves a dead path in the row),
            // and nothing else re-reads the rows a user did not follow.
            new ScheduledTask(
                name: TaskName::RefreshEntities,
                cron: '0 6 * * 1',
                frequency: 'tasks.weekly',
                queue: fn () => RefreshEntityMetadata::queueTask(),
            ),
            new ScheduledTask(
                name: TaskName::RefreshCollections,
                cron: '0 5 * * 1',
                frequency: 'tasks.weekly',
                queue: fn () => RefreshCollections::queueTask(),
            ),
            new ScheduledTask(
                name: TaskName::PrefetchTrending,
                cron: '0 */3 * * *',
                frequency: 'tasks.every_3_hours',
                queue: fn () => PrefetchTrending::queueTask(),
            ),
            // Between the metadata refresh (4:00) and notify-releases (5:30),
            // so a person's fresh credits already count into today's pings.
            new ScheduledTask(
                name: TaskName::RefreshFollowedPeople,
                cron: '0 5 * * *',
                frequency: 'common.daily',
                queue: fn () => RefreshFollowedCreators::queueTask(CreatorTable::People),
            ),
            // Alongside the followed-people sweep, before notify-releases so a
            // company's fresh upcoming games count into today's pings.
            new ScheduledTask(
                name: TaskName::RefreshFollowedCompanies,
                cron: '10 5 * * *',
                frequency: 'common.daily',
                queue: fn () => RefreshFollowedCreators::queueTask(CreatorTable::Companies),
            ),
            // After the 4:00 metadata refresh, so today's releases are fresh,
            // and half an hour ahead of the digest's earliest slot in the
            // instance timezone — today's releases make that day's digest.
            new ScheduledTask(
                name: TaskName::NotifyReleases,
                cron: '30 5 * * *',
                frequency: 'common.daily',
                queue: fn () => NotifyTodaysReleases::queueTask(),
            ),
            // On the hour, so a user's digest lands at a round local 8:00 once
            // their LOCAL clock passes it, whatever their timezone. A zone
            // offset by :30 or :45 (India, Nepal) still gets it within the
            // hour — the slot is a floor, not an alarm.
            new ScheduledTask(
                name: TaskName::DiscordDigest,
                cron: '0 * * * *',
                frequency: 'common.hourly',
                queue: fn () => SendDiscordDigests::queueTask(),
            ),
            // Imports through connected accounts; each source keeps its own
            // pace (Simkl daily, Tracearr hourly), this only looks for due ones.
            new ScheduledTask(
                name: TaskName::AutoImport,
                cron: '15 * * * *',
                frequency: 'common.hourly',
                queue: fn () => QueueAutoImports::queueTask(),
            ),
            // Just past midnight, when yesterday's "airs today" becomes aired:
            // Up Next and the progress counters move on without a write.
            new ScheduledTask(
                name: TaskName::ProjectAiredEpisodes,
                cron: '5 0 * * *',
                frequency: 'common.daily',
                queue: fn () => ProjectAiredEpisodes::queueTask(),
            ),
            // After the morning's refreshes and pings, out of their way: the
            // shelves it feeds are read whenever, nothing waits on it.
            new ScheduledTask(
                name: TaskName::RefreshSuggestionSets,
                cron: '0 7 * * *',
                frequency: 'common.daily',
                queue: fn () => RefreshSuggestionSets::queueTask(),
            ),
            // Daily, though a title is asked about once in months: a run takes
            // on a few hundred titles, so a new library is covered over
            // several mornings and a covered one costs nothing.
            new ScheduledTask(
                name: TaskName::RefreshRelations,
                cron: '30 7 * * *',
                frequency: 'common.daily',
                queue: fn () => RefreshRelations::queueTask(),
            ),
            // Daily: an event comes in shortly before it airs and its titles after.
            new ScheduledTask(
                name: TaskName::RefreshEvents,
                cron: '45 7 * * *',
                frequency: 'common.daily',
                queue: fn () => RefreshEvents::queueTask(),
            ),
            new ScheduledTask(
                name: TaskName::Cleanup,
                cron: '30 3 * * *',
                frequency: 'common.daily',
                queue: fn () => CleanupExpiredData::queueTask(),
            ),
        ];
    }

    public static function find(TaskName $name): ?ScheduledTask
    {
        foreach (self::all() as $task) {
            if ($task->name === $name) {
                return $task;
            }
        }

        return null;
    }
}
