<?php

namespace App\Http\Mcp;

use App\Catalog\MediaTypeRegistry;
use App\Http\Mcp\Tools\AddToList;
use App\Http\Mcp\Tools\CancelRewatch;
use App\Http\Mcp\Tools\CheckTitles;
use App\Http\Mcp\Tools\CreateList;
use App\Http\Mcp\Tools\DeleteHistoryEntry;
use App\Http\Mcp\Tools\DeleteList;
use App\Http\Mcp\Tools\FollowCreator;
use App\Http\Mcp\Tools\FollowReleases;
use App\Http\Mcp\Tools\GetCalendar;
use App\Http\Mcp\Tools\GetCountdowns;
use App\Http\Mcp\Tools\GetHistory;
use App\Http\Mcp\Tools\GetLibrary;
use App\Http\Mcp\Tools\GetList;
use App\Http\Mcp\Tools\GetLists;
use App\Http\Mcp\Tools\GetNotifications;
use App\Http\Mcp\Tools\GetSuggestions;
use App\Http\Mcp\Tools\GetTitle;
use App\Http\Mcp\Tools\GetUpNext;
use App\Http\Mcp\Tools\LogProgress;
use App\Http\Mcp\Tools\MarkNotificationsRead;
use App\Http\Mcp\Tools\MarkWatched;
use App\Http\Mcp\Tools\Rate;
use App\Http\Mcp\Tools\RemoveFromList;
use App\Http\Mcp\Tools\RemoveRating;
use App\Http\Mcp\Tools\Search;
use App\Http\Mcp\Tools\SetFavorite;
use App\Http\Mcp\Tools\SetStatus;
use App\Http\Mcp\Tools\StartRewatch;
use App\Http\Mcp\Tools\UnmarkWatched;
use App\Http\Mcp\Tools\UpdateList;
use App\Models\MediaList;
use Illuminate\Container\Attributes\Config;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Transport;

/**
 * The user's library for an AI assistant (Claude and any other MCP client):
 * what is next, what comes out, and ticking off or rating what was watched.
 * The tools are the API's doors in another protocol: the same token and
 * abilities, the same actions, the same answers. The server names itself
 * after the instance, so a user with two instances can tell them apart.
 */
final class LibraryServer extends Server
{
    /** Every tool on one page of `tools/list`: a client that does not follow the cursor still sees them all. */
    public int $defaultPaginationLength = 50;

    protected array $tools = [
        Search::class,
        CheckTitles::class,
        GetTitle::class,
        GetHistory::class,
        GetLibrary::class,
        GetUpNext::class,
        GetCalendar::class,
        GetCountdowns::class,
        GetLists::class,
        GetList::class,
        GetSuggestions::class,
        GetNotifications::class,
        MarkWatched::class,
        UnmarkWatched::class,
        DeleteHistoryEntry::class,
        Rate::class,
        RemoveRating::class,
        SetStatus::class,
        LogProgress::class,
        SetFavorite::class,
        FollowReleases::class,
        FollowCreator::class,
        StartRewatch::class,
        CancelRewatch::class,
        CreateList::class,
        UpdateList::class,
        DeleteList::class,
        AddToList::class,
        RemoveFromList::class,
        MarkNotificationsRead::class,
    ];

    public function __construct(
        Transport $transport,
        MediaTypeRegistry $types,
        #[Config('app.name')] string $name,
        #[Config('tracker.version')] string $version,
    ) {
        parent::__construct($transport);

        $this->name = $name;
        $this->version = $version;
        $this->instructions = self::instructions(array_keys($types->all()));
    }

    /**
     * @param  list<string>  $types
     */
    private static function instructions(array $types): string
    {
        $types = implode(', ', $types);
        $watchlist = MediaList::SYSTEM_WATCHLIST;

        return <<<MARKDOWN
            A media tracker: the user's history, ratings, lists and releases of the titles they follow. Media types on
            this instance: {$types}.

            Every title, season and episode is addressed by a `type` and a `ref`. Take both from a tool's answer and
            never guess a ref: `search` and `check_titles` find titles, `get_up_next`, `get_library` and `get_list` list
            what the user has. To learn which of many titles the user knows, call `check_titles` once rather than
            `search` many times.

            Answers are compact: a field that is missing is false, empty or unknown. A title carries the user's `state`
            (watched, plays, rating, favorite, status, listIds…). A write answers with the title as it now stands and
            `states`, every state the write moved (an episode moves its season and its show too).

            The Backlog, where the user keeps what they mean to watch, is the list whose `systemKey` is "{$watchlist}".
            MARKDOWN;
    }
}
