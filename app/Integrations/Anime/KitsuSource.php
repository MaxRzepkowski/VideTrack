<?php

namespace App\Integrations\Anime;

use App\Integrations\Export\ListStatus;
use App\Integrations\Import\Data\SetupNote;
use App\Integrations\Import\Rows\SkipRow;
use App\Support\Forms\CredentialField;
use App\Support\Payload;

/**
 * A public Kitsu library (JSON:API, no key), read the way Yamtrack reads it:
 * the user by `filter[name]` — or their numeric id straight away — then
 * `library-entries` with each title and its external mappings included,
 * following `links.next`. A title is placed by its `myanimelist/{kind}`
 * mapping; one without it, or hidden by Kitsu as adult content, is reported.
 */
final class KitsuSource extends AnimeProfileSource
{
    public const CODE = 'kitsu';

    private const PAGE = 500;

    private const STATUSES = [
        'current' => ListStatus::Current,
        'completed' => ListStatus::Completed,
        'on_hold' => ListStatus::OnHold,
        'dropped' => ListStatus::Dropped,
        'planned' => ListStatus::Planned,
    ];

    public function __construct(AnimeListRows $translate, private readonly KitsuClient $client)
    {
        parent::__construct($translate);
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.kitsu_profile');
    }

    public function description(): string
    {
        return __('import.your_anime_and_manga_list_read');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_kitsu');
    }

    public function credentialsSchema(): array
    {
        return [new CredentialField('username', __('common.username'), help: __('import.or_the_number_from_your_kitsu'))];
    }

    public function notes(): array
    {
        return [new SetupNote(__('import.the_list_has_to_be_public_2'))];
    }

    protected function fetch(string $username): array
    {
        $userId = ctype_digit($username) ? $username : $this->userId($username);

        return [...$this->library($userId, ListMedium::Anime), ...$this->library($userId, ListMedium::Manga)];
    }

    private function userId(string $username): string
    {
        $users = Payload::list($this->client->get('users', ['filter[name]' => $username])['data'] ?? null);

        if (count($users) > 1) {
            throw new AnimeListNotFound('import.kitsu_many_users', ['username' => $username]);
        }

        return Payload::id($users[0]['id'] ?? null)
            ?? throw new AnimeListNotFound('import.anime_list_no_user', ['service' => 'Kitsu', 'username' => $username]);
    }

    /**
     * @return list<AnimeListEntry|SkipRow>
     */
    private function library(string $userId, ListMedium $medium): array
    {
        $kind = $medium->value;
        $entries = [];
        $url = 'library-entries';
        $query = [
            'filter[user_id]' => $userId,
            'filter[kind]' => $kind,
            'include' => "{$kind},{$kind}.mappings",
            "fields[{$kind}]" => $medium === ListMedium::Anime ? 'canonicalTitle,episodeCount,mappings' : 'canonicalTitle,chapterCount,mappings',
            'fields[mappings]' => 'externalSite,externalId',
            'page[limit]' => self::PAGE,
        ];

        while ($url !== null) {
            $page = $this->client->get($url, $query);
            $included = $this->included($page);

            foreach (Payload::list($page['data'] ?? null) as $item) {
                $entry = $this->entry($item, $medium, $included);

                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }

            // The next link is absolute and carries the whole query itself —
            // and must travel without a query of ours, since even an empty
            // one replaces the link's own.
            $url = Payload::string(Payload::map($page['links'] ?? null), 'next');
            $query = null;
        }

        return $entries;
    }

    /**
     * Included records by "type:id".
     *
     * @param  array<array-key, mixed>  $page
     * @return array<string, array<array-key, mixed>>
     */
    private function included(array $page): array
    {
        $index = [];

        foreach (Payload::list($page['included'] ?? null) as $record) {
            $type = Payload::string($record, 'type');
            $id = Payload::id($record['id'] ?? null);

            if ($type !== null && $id !== null) {
                $index["{$type}:{$id}"] = $record;
            }
        }

        return $index;
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @param  array<string, array<array-key, mixed>>  $included
     */
    private function entry(array $item, ListMedium $medium, array $included): AnimeListEntry|SkipRow|null
    {
        // Kitsu names its relationships and resources by the list's word.
        $kind = $medium->value;
        $attributes = Payload::map($item['attributes'] ?? null);
        $status = self::STATUSES[Payload::text($attributes, 'status')] ?? null;

        if ($status === null) {
            return null;
        }

        $refId = Payload::id(data_get($item, "relationships.{$kind}.data.id"));
        $media = $refId === null ? null : ($included["{$kind}:{$refId}"] ?? null);

        // Kitsu leaves the relationship empty for titles it hides as adult.
        if ($media === null) {
            return new SkipRow('import.skip_kitsu_hidden');
        }

        $mediaAttributes = Payload::map($media['attributes'] ?? null);
        $title = Payload::string($mediaAttributes, 'canonicalTitle') ?? "Kitsu {$kind} {$refId}";
        $malId = $this->malId($media, $medium, $included);

        if ($malId === null) {
            return new SkipRow('import.skip_kitsu_no_mal_id', ['label' => $title]);
        }

        $rating = Payload::positiveInt($attributes, 'ratingTwenty');

        return new AnimeListEntry(
            $medium,
            $malId,
            $title,
            $status,
            // Kitsu rates 2–20; half-points round up so nothing lands on 0.
            $rating === null ? null : (int) ceil($rating / 2),
            Payload::int($attributes, 'progress') ?? 0,
            Payload::positiveInt($mediaAttributes, $medium === ListMedium::Anime ? 'episodeCount' : 'chapterCount'),
            // A day the user picked, sent as its midnight UTC: read as a moment,
            // it would fall on the day before west of Greenwich.
            Payload::calendarDay($attributes['startedAt'] ?? null),
            Payload::calendarDay($attributes['finishedAt'] ?? null),
            Payload::instant($attributes['updatedAt'] ?? null),
        );
    }

    /**
     * @param  array<array-key, mixed>  $media
     * @param  array<string, array<array-key, mixed>>  $included
     */
    private function malId(array $media, ListMedium $medium, array $included): ?int
    {
        foreach (Payload::list(data_get($media, 'relationships.mappings.data')) as $ref) {
            $mappingId = Payload::id($ref['id'] ?? null);
            $mapping = $mappingId === null ? [] : ($included["mappings:{$mappingId}"] ?? []);
            $attributes = Payload::map($mapping['attributes'] ?? null);
            $externalId = Payload::string($attributes, 'externalId');

            // Some mappings carry junk (Farmagia's MAL id reads "anime").
            if (Payload::string($attributes, 'externalSite') === "myanimelist/{$medium->value}"
                && $externalId !== null
                && ctype_digit($externalId)) {
                return (int) $externalId;
            }
        }

        return null;
    }
}
