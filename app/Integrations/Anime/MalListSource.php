<?php

namespace App\Integrations\Anime;

use App\Integrations\Export\ListStatus;
use App\Integrations\Import\Data\SetupNote;
use App\Support\Payload;
use Carbon\CarbonImmutable;

/**
 * A public MyAnimeList profile: `GET /users/{name}/animelist|mangalist` with
 * the instance's MAL client id, no OAuth (the way Yamtrack reads it). Pages
 * of 1000 until `paging.next` runs out.
 */
final class MalListSource extends AnimeProfileSource
{
    public const CODE = 'mal';

    private const PAGE = 1000;

    private const STATUSES = [
        'watching' => ListStatus::Current,
        'reading' => ListStatus::Current,
        'completed' => ListStatus::Completed,
        'on_hold' => ListStatus::OnHold,
        'dropped' => ListStatus::Dropped,
        'plan_to_watch' => ListStatus::Planned,
        'plan_to_read' => ListStatus::Planned,
    ];

    public function __construct(AnimeListRows $translate, private readonly MalListClient $client)
    {
        parent::__construct($translate);
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.myanimelist_profile');
    }

    public function description(): string
    {
        return __('import.your_anime_and_manga_list_read');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_myanimelist');
    }

    public function notes(): array
    {
        return [new SetupNote(__('import.the_list_has_to_be_public_3'))];
    }

    protected function fetch(string $username): array
    {
        return [...$this->list($username, ListMedium::Anime), ...$this->list($username, ListMedium::Manga)];
    }

    /**
     * @return list<AnimeListEntry>
     */
    private function list(string $username, ListMedium $medium): array
    {
        $entries = [];
        $offset = 0;

        do {
            $page = $this->page($username, $medium, $offset);

            foreach (Payload::list($page['data'] ?? null) as $item) {
                $entry = $this->entry($item, $medium);

                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }

            $offset += self::PAGE;
        } while (Payload::string(Payload::map($page['paging'] ?? null), 'next') !== null);

        return $entries;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function page(string $username, ListMedium $medium, int $offset): array
    {
        return $this->client->page($username, $medium->value, $offset, self::PAGE);
    }

    /**
     * @param  array<array-key, mixed>  $item
     */
    private function entry(array $item, ListMedium $medium): ?AnimeListEntry
    {
        $node = Payload::map($item['node'] ?? null);
        $list = Payload::map($item['list_status'] ?? null);
        $status = self::STATUSES[Payload::text($list, 'status')] ?? null;
        $id = Payload::int($node, 'id');

        if ($id === null || $status === null) {
            return null;
        }

        return new AnimeListEntry(
            $medium,
            $id,
            Payload::string($node, 'title'),
            $status,
            Payload::positiveInt($list, 'score'),
            Payload::int($list, $medium === ListMedium::Anime ? 'num_episodes_watched' : 'num_chapters_read') ?? 0,
            Payload::positiveInt($node, $medium === ListMedium::Anime ? 'num_episodes' : 'num_chapters'),
            $this->date($list['start_date'] ?? null),
            $this->date($list['finish_date'] ?? null),
            $this->date($list['updated_at'] ?? null),
        );
    }

    /**
     * MAL dates are partial when the user left them so: "2020", "2020-03",
     * "2020-03-04", or a full timestamp for updated_at.
     */
    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $value = match (strlen($value)) {
            4 => "{$value}-01-01",
            7 => "{$value}-01",
            default => $value,
        };

        return Payload::instant($value);
    }
}
