<?php

namespace App\Integrations\MangaBaka;

use App\Catalog\MediaKind;
use App\Enums\MediaUserStatus;
use App\Integrations\Import\Data\ImportDrop;
use App\Integrations\Import\Data\ImportListEntry;
use App\Integrations\Import\Data\ImportListRef;
use App\Integrations\Import\Data\ImportMark;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\ImportRating;
use App\Integrations\Import\Data\ImportStatus;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Data\TitleQuery;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Library\RatingScale;
use App\Support\Payload;

/**
 * One entry of a MangaBaka library (`/v2/my/library`: the entry, its
 * series, the lists it is on) as an import row.
 *
 * MangaBaka keeps the series' MyAnimeList id, which is our manga's ref; a
 * series without one is searched by its primary title and year, which must
 * match exactly. Completed is one read on the day it was finished (re-reads
 * have no dates, so they are not made up); re-reading is that read and a new
 * one in progress; reading is in progress, paused on hold, dropped dropped,
 * each with the chapter reached;
 * plan to read and considering are the watchlist. A rating out of 100 goes
 * onto our scale.
 */
final class MangaBakaRows
{
    /**
     * @param  array<array-key, mixed>  $item
     * @param  array<string, ImportListRef>  $lists  the lists already named, by MangaBaka's list id
     */
    public function entry(array $item, array $lists): MediaRow|SkipRow|null
    {
        $entry = Payload::map($item['entry'] ?? null);
        $series = Payload::map($item['series'] ?? null);
        $state = Payload::enum($entry, 'state', MangaBakaState::class);

        if ($state === null || $series === []) {
            return null;
        }

        $subject = $this->subject($series);

        if ($subject === null) {
            return new SkipRow('import.skip_mangabaka_no_title');
        }

        $started = Payload::instant($entry['start_date'] ?? null);
        $finished = Payload::instant($entry['finish_date'] ?? null);
        // A chapter may be a half (12.5): the one begun counts as reached.
        $reached = Payload::float($entry, 'progress_chapter');
        $chapter = $reached === null || $reached < 1 ? null : (int) floor($reached);
        $rating = RatingScale::percent(Payload::float($entry, 'rating'));
        $on = [];

        foreach (array_keys($this->lists($item)) as $id) {
            if (isset($lists[$id])) {
                $on[] = new ImportListEntry($lists[$id]);
            }
        }

        return new MediaRow(
            $subject,
            // A re-read under way was finished before: that read stays, and the new one is open.
            plays: in_array($state, [MangaBakaState::Completed, MangaBakaState::Rereading], true) ? [new ImportPlay($finished)] : [],
            rating: $rating === null ? null : new ImportRating($rating, $finished),
            status: match ($state) {
                MangaBakaState::Reading, MangaBakaState::Rereading => new ImportStatus(MediaUserStatus::InProgress, $started, $chapter),
                MangaBakaState::Paused => new ImportStatus(MediaUserStatus::OnHold, $started, $chapter),
                default => null,
            },
            drop: $state === MangaBakaState::Dropped ? new ImportDrop($finished, $chapter) : null,
            watchlist: in_array($state, [MangaBakaState::PlanToRead, MangaBakaState::Considering], true)
                ? new ImportMark(Payload::instant($entry['created_at'] ?? null))
                : null,
            lists: $on,
        );
    }

    /**
     * The lists an entry is on, as the import names them: private on
     * MangaBaka, private here.
     *
     * @param  array<array-key, mixed>  $item
     * @return array<string, ImportListRef> MangaBaka's list id => the list
     */
    public function lists(array $item): array
    {
        $refs = [];

        foreach (Payload::list($item['lists'] ?? null) as $list) {
            $id = Payload::id($list['id'] ?? null);
            $name = Payload::string($list, 'name');

            if ($id !== null && $name !== null) {
                $refs[$id] = new ImportListRef($name, isPublic: Payload::bool($list, 'is_private') === false);
            }
        }

        return $refs;
    }

    /**
     * @param  array<array-key, mixed>  $series
     */
    private function subject(array $series): ?MediaSubject
    {
        $title = $this->primaryTitle($series);

        if ($title === null) {
            return null;
        }

        $mal = Payload::id(data_get($series, 'source.my_anime_list.id'));

        return $mal !== null
            ? MediaSubject::direct(MediaKind::Manga->value, 'mal', $mal, $title)
            : MediaSubject::external(MediaKind::Manga, [], $title, byTitle: new TitleQuery(
                $title,
                Payload::yearOf(Payload::string(Payload::map($series['published'] ?? null), 'start_date')),
                exactTitle: true,
            ));
    }

    /**
     * The series' primary title, else its first official one, else any.
     *
     * @param  array<array-key, mixed>  $series
     */
    private function primaryTitle(array $series): ?string
    {
        $titles = Payload::list($series['titles'] ?? null);

        foreach ($titles as $title) {
            if (Payload::bool($title, 'is_primary') === true) {
                return Payload::string($title, 'title');
            }
        }

        foreach ($titles as $title) {
            if (in_array('official', Payload::strings($title['traits'] ?? null), true)) {
                return Payload::string($title, 'title');
            }
        }

        return Payload::string($titles[0] ?? [], 'title');
    }
}
