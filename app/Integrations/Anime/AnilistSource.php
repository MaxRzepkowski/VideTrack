<?php

namespace App\Integrations\Anime;

use App\Integrations\Export\ListStatus;
use App\Integrations\Import\Data\SetupNote;
use App\Integrations\Import\Rows\SkipRow;
use App\Support\Payload;
use Carbon\CarbonImmutable;

/**
 * A public AniList profile: one GraphQL query, no token, both lists at once
 * (the query Yamtrack sends). Scores come back already on a 10-point scale
 * whatever format the user rates in; custom lists repeat entries of the
 * status lists and are left out. AniList names titles by idMal, so an entry
 * without one cannot be placed.
 */
final class AnilistSource extends AnimeProfileSource
{
    public const CODE = 'anilist';

    private const QUERY = <<<'GRAPHQL'
        query ($userName: String) {
            anime: MediaListCollection(userName: $userName, type: ANIME) {
                lists {
                    isCustomList
                    entries {
                        media { title { userPreferred } idMal episodes }
                        status
                        score(format: POINT_10_DECIMAL)
                        progress
                        startedAt { year month day }
                        completedAt { year month day }
                        updatedAt
                    }
                }
            }
            manga: MediaListCollection(userName: $userName, type: MANGA) {
                lists {
                    isCustomList
                    entries {
                        media { title { userPreferred } idMal chapters }
                        status
                        score(format: POINT_10_DECIMAL)
                        progress
                        startedAt { year month day }
                        completedAt { year month day }
                        updatedAt
                    }
                }
            }
        }
        GRAPHQL;

    private const STATUSES = [
        'CURRENT' => ListStatus::Current,
        // A rewatch in progress: it was finished before.
        'REPEATING' => ListStatus::Completed,
        'COMPLETED' => ListStatus::Completed,
        'PAUSED' => ListStatus::OnHold,
        'DROPPED' => ListStatus::Dropped,
        'PLANNING' => ListStatus::Planned,
    ];

    public function __construct(AnimeListRows $translate, private readonly AnilistClient $client)
    {
        parent::__construct($translate);
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.anilist_profile');
    }

    public function description(): string
    {
        return __('import.your_anime_and_manga_list_read');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_anilist');
    }

    public function notes(): array
    {
        return [new SetupNote(__('import.the_list_has_to_be_public'))];
    }

    protected function fetch(string $username): array
    {
        $data = $this->client->query(self::QUERY, $username);

        return [
            ...$this->collection($data['anime'] ?? null, ListMedium::Anime),
            ...$this->collection($data['manga'] ?? null, ListMedium::Manga),
        ];
    }

    /**
     * @return list<AnimeListEntry|SkipRow>
     */
    private function collection(mixed $collection, ListMedium $medium): array
    {
        $entries = [];

        foreach (Payload::list(Payload::map($collection)['lists'] ?? null) as $list) {
            if (Payload::bool($list, 'isCustomList') === true) {
                continue;
            }

            foreach (Payload::list($list['entries'] ?? null) as $item) {
                $entry = $this->entry($item, $medium);

                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    /**
     * @param  array<array-key, mixed>  $item
     */
    private function entry(array $item, ListMedium $medium): AnimeListEntry|SkipRow|null
    {
        $media = Payload::map($item['media'] ?? null);
        $status = self::STATUSES[Payload::text($item, 'status')] ?? null;

        if ($status === null) {
            return null;
        }

        $malId = Payload::int($media, 'idMal');
        $title = Payload::string(Payload::map($media['title'] ?? null), 'userPreferred');

        if ($malId === null) {
            // Named by the service and its own id when it has no title: no words to translate.
            $anilistId = Payload::int($media, 'id');
            $label = $title ?? ($anilistId === null ? 'AniList' : "AniList {$anilistId}");

            return new SkipRow('import.skip_anilist_no_mal_id', ['label' => $label]);
        }

        $score = Payload::float($item, 'score');
        $score = $score === null ? 0 : (int) round($score);
        $updated = Payload::positiveInt($item, 'updatedAt');

        return new AnimeListEntry(
            $medium,
            $malId,
            $title,
            $status,
            $score >= 1 ? $score : null,
            Payload::int($item, 'progress') ?? 0,
            Payload::positiveInt($media, $medium === ListMedium::Anime ? 'episodes' : 'chapters'),
            $this->date($item['startedAt'] ?? null),
            $this->date($item['completedAt'] ?? null),
            $updated === null ? null : CarbonImmutable::createFromTimestampUTC($updated),
        );
    }

    /**
     * AniList's fuzzy date: a year, maybe a month, maybe a day.
     */
    private function date(mixed $value): ?CarbonImmutable
    {
        $value = Payload::map($value);
        $year = Payload::int($value, 'year');

        return $year === null ? null : Payload::day($year, Payload::int($value, 'month') ?? 1, Payload::int($value, 'day') ?? 1);
    }
}
