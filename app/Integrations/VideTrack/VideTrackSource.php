<?php

namespace App\Integrations\VideTrack;

use App\Catalog\Creators\CreatorKind;
use App\Integrations\Import\Contracts\ReadsFile;
use App\Integrations\Import\Data\ImportCycle;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportListEntry;
use App\Integrations\Import\Data\ImportListRef;
use App\Integrations\Import\Data\ImportManifest;
use App\Integrations\Import\Data\ImportMark;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\ImportRating;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ImportStatus;
use App\Integrations\Import\Data\ManifestCategory;
use App\Integrations\Import\Rows\CompanyListRow;
use App\Integrations\Import\Rows\CreatorFollowRow;
use App\Integrations\Import\Rows\ImportRow;
use App\Integrations\Import\Rows\ListRow;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\PersonListRow;
use App\Integrations\Import\UnpackedFileLimit;
use App\Integrations\VideTrack\Backup\BackupCreator;
use App\Integrations\VideTrack\Backup\BackupCycle;
use App\Integrations\VideTrack\Backup\BackupFavorite;
use App\Integrations\VideTrack\Backup\BackupFollow;
use App\Integrations\VideTrack\Backup\BackupFormat;
use App\Integrations\VideTrack\Backup\BackupList;
use App\Integrations\VideTrack\Backup\BackupListItem;
use App\Integrations\VideTrack\Backup\BackupPlay;
use App\Integrations\VideTrack\Backup\BackupRating;
use App\Integrations\VideTrack\Backup\BackupSection;
use App\Integrations\VideTrack\Backup\BackupStatus;
use App\Integrations\VideTrack\Backup\InvalidBackupEntry;
use App\Library\RecordKind;
use App\Support\Payload;
use Generator;
use LogicException;

/**
 * Restores an account from a VideTrack backup — keyed by our own
 * coordinates, so nothing is looked up. The entries are read by the same
 * classes that wrote them ({@see Backup\BackupWriter}).
 */
final class VideTrackSource implements ReadsFile
{
    public const CODE = 'videtrack';

    /** @var array{path: string, data: array<array-key, mixed>|null}|null */
    private ?array $decoded = null;

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.videtrack_backup_json');
    }

    public function description(): string
    {
        return __('import.a_file_downloaded_from_settings_export');
    }

    public function taskLabel(): string
    {
        return __('import.import_videtrack_backup');
    }

    public function accept(): string
    {
        return '.json';
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        $path = (string) $input->path;

        if (is_file($path) && filesize($path) > UnpackedFileLimit::bytes()) {
            return ImportManifest::invalid(__('import.a_file_in_this_export_is'));
        }

        $data = $this->data($input);

        if ($data === null) {
            return ImportManifest::invalid(__('import.the_file_is_not_a_videtrack'));
        }

        $categories = [];

        foreach (BackupSection::cases() as $section) {
            $entries = $this->entries($data, $section);
            $count = count($entries);

            // Every list item is a row of its own.
            if ($section === BackupSection::Lists) {
                foreach ($entries as $list) {
                    $count += is_array($list['items'] ?? null) ? count($list['items']) : 0;
                }
            }

            $categories[] = new ManifestCategory($section->value, $section->label(), $count);
        }

        return ImportManifest::whole($categories);
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $data = $this->data($input) ?? [];

        foreach (BackupSection::cases() as $section) {
            foreach ($this->entries($data, $section) as $index => $entry) {
                try {
                    foreach ($this->read($section, $entry) as $row) {
                        yield $row;
                    }
                } catch (InvalidBackupEntry $e) {
                    yield $e->skip($section->value.' #'.($index + 1));
                }
            }
        }
    }

    /**
     * The rows one entry of a section restores.
     *
     * @param  array<mixed>  $entry
     * @return iterable<ImportRow>
     */
    private function read(BackupSection $section, array $entry): iterable
    {
        return match ($section) {
            BackupSection::Cycles => [$this->cycle(BackupCycle::fromArray($entry))],
            BackupSection::Follows => [$this->follow(BackupFollow::fromArray($entry))],
            BackupSection::Plays => [$this->play(BackupPlay::fromArray($entry))],
            BackupSection::Ratings => [$this->rating(BackupRating::fromArray($entry))],
            BackupSection::Favorites => [$this->favorite(BackupFavorite::fromArray($entry))],
            BackupSection::Statuses => $this->status(BackupStatus::fromArray($entry)),
            BackupSection::Lists => $this->list(BackupList::fromArray($entry)),
        };
    }

    /**
     * The decoded export, read once per run; null when it is not ours.
     *
     * @return array<array-key, mixed>|null
     */
    private function data(ImportInput $input): ?array
    {
        $path = (string) $input->path;

        if ($this->decoded !== null && $this->decoded['path'] === $path) {
            return $this->decoded['data'];
        }

        $contents = is_file($path) ? file_get_contents($path) : false;
        $data = $contents === false ? null : json_decode($contents, true);

        $this->decoded = ['path' => $path, 'data' => BackupFormat::reads($data) ? $data : null];

        return $this->decoded['data'];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<array<array-key, mixed>>
     */
    private function entries(array $data, BackupSection $section): array
    {
        return Payload::list($data[$section->value] ?? null);
    }

    private function cycle(BackupCycle $cycle): MediaRow
    {
        return new MediaRow($cycle->position->subject(), cycles: [new ImportCycle($cycle->number, $cycle->startedAt)]);
    }

    private function follow(BackupFollow $follow): MediaRow|CreatorFollowRow
    {
        $target = $follow->target;

        return $target instanceof BackupCreator
            ? new CreatorFollowRow($target->ref(), $target->name, $follow->mode)
            : new MediaRow($target->subject(), follow: $follow->mode);
    }

    private function play(BackupPlay $play): MediaRow
    {
        return new MediaRow($play->position->subject(), plays: [new ImportPlay($play->watchedAt, $play->outcome, $play->amount, $play->cycle, source: $play->source, record: RecordKind::Backup)]);
    }

    private function rating(BackupRating $rating): MediaRow
    {
        return new MediaRow($rating->position->subject(), rating: new ImportRating($rating->rating, $rating->ratedAt));
    }

    private function favorite(BackupFavorite $favorite): MediaRow
    {
        return new MediaRow($favorite->position->subject(), favorite: new ImportMark($favorite->favoritedAt));
    }

    /**
     * @return list<MediaRow>
     */
    private function status(BackupStatus $status): array
    {
        return [new MediaRow($status->position->subject(), status: new ImportStatus($status->status, $status->setAt, $status->progress))];
    }

    /**
     * @return Generator<int, ImportRow>
     */
    private function list(?BackupList $list): Generator
    {
        if ($list === null) {
            return;
        }

        $ref = $list->ref();

        yield new ListRow($ref);

        foreach ($list->items as $item) {
            yield $this->listItem($ref, $item);
        }

        foreach ($list->unreadable as $number => $unreadable) {
            yield $unreadable->skip("{$list->title} #{$number}");
        }
    }

    /**
     * People and companies restore from the file alone, no provider round-trip.
     */
    private function listItem(ImportListRef $list, BackupListItem $item): ImportRow
    {
        $target = $item->target;

        if (! $target instanceof BackupCreator) {
            return new MediaRow($target->subject(), lists: [new ImportListEntry($list, $item->addedAt)]);
        }

        return match ($target->kind) {
            CreatorKind::Person => new PersonListRow($list, $target->provider, $target->ref, $target->name, $item->addedAt),
            CreatorKind::Company, CreatorKind::Network => new CompanyListRow($list, $target->provider, $target->ref, $target->kind, $target->name, $item->addedAt),
            CreatorKind::Collection => throw new LogicException('A collection goes on no list; BackupListItem refuses one.'),
        };
    }
}
