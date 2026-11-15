<?php

namespace App\Catalog\Providers\Igdb;

use App\Catalog\Data\DiscoverFilter;
use App\Catalog\Data\DiscoverFilterOption;
use App\Catalog\DiscoverFilterKind;
use App\Catalog\ItemNotFoundException;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRejected;
use App\Support\Payload;
use Closure;
use Collator;
use Locale;

/**
 * The Discover filters on how a game is played: the language it speaks, its
 * modes (single player, co-op, split screen), the view it is played from.
 * Each is one of IGDB's dictionaries; a dictionary that will not load costs
 * its own filter, not the page.
 */
final readonly class IgdbPlayFilters
{
    /** Apicalypse's page ceiling: every vocabulary (languages, modes, perspectives) fits one page. */
    private const VOCABULARY_LIMIT = 500;

    /** filter key => the games field it narrows. */
    private const FIELDS = [
        'language' => 'language_supports.language',
        'modes' => 'game_modes',
        'perspective' => 'player_perspectives',
    ];

    public function __construct(
        private IgdbClient $client,
        private IgdbPayload $payload,
    ) {}

    /** @return list<DiscoverFilter> */
    public function filters(string $locale): array
    {
        return [
            $this->dictionary($locale, 'language', __('discover.language'), DiscoverFilterKind::Select, 'languages', 'name, locale',
                fn (array $row): ?string => $this->languageName($row, $locale)),
            $this->dictionary($locale, 'modes', __('discover.game_modes'), DiscoverFilterKind::MultiSelect, 'game_modes', 'name',
                fn (array $row): ?string => Payload::string($row, 'name')),
            $this->dictionary($locale, 'perspective', __('discover.perspective'), DiscoverFilterKind::MultiSelect, 'player_perspectives', 'name',
                fn (array $row): ?string => Payload::string($row, 'name')),
        ];
    }

    /**
     * A language named in the reader's language ("polski"); IGDB's English
     * name where its locale names nothing ICU knows.
     *
     * @param  array<array-key, mixed>  $row
     */
    private function languageName(array $row, string $locale): ?string
    {
        $tag = Payload::string($row, 'locale');
        $name = $tag === null ? false : Locale::getDisplayLanguage($tag, $locale);

        return $name === false || $name === $tag ? Payload::string($row, 'name') : $name;
    }

    /**
     * The where clauses the chosen values ask for. A list field matches any of
     * the values ("(N)" is Apicalypse containment), so several modes widen the
     * results rather than narrowing them to games with all of them.
     *
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    public function clauses(array $filters): array
    {
        $clauses = [];

        foreach (self::FIELDS as $key => $field) {
            $ids = $this->payload->numericIds($filters[$key] ?? null);

            if ($ids !== []) {
                $clauses[] = "{$field} = (".implode(',', $ids).')';
            }
        }

        return $clauses;
    }

    /**
     * One dictionary as filter options, alphabetical by the label shown.
     *
     * @param  Closure(array<array-key, mixed>): ?string  $labelOf
     */
    private function dictionary(string $locale, string $key, string $label, DiscoverFilterKind $kind, string $endpoint, string $fields, Closure $labelOf): DiscoverFilter
    {
        try {
            $rows = $this->client->query($endpoint, "fields {$fields}; limit ".self::VOCABULARY_LIMIT.';', $this->payload->ttl('listing'), requireResults: true);
        } catch (ItemNotFoundException|ProviderException|ProviderRejected) {
            return new DiscoverFilter($key, $label, $kind, unavailable: true);
        }

        $options = [];

        foreach (Payload::rows($rows) as $row) {
            $ref = Payload::id($row['id'] ?? null);
            $name = $labelOf($row);

            if ($ref !== null && $name !== null && $name !== '') {
                $options[] = new DiscoverFilterOption($ref, $name);
            }
        }

        $collator = new Collator($locale);
        usort($options, fn (DiscoverFilterOption $a, DiscoverFilterOption $b): int => (int) $collator->compare($a->label, $b->label));

        return new DiscoverFilter($key, $label, $kind, $options);
    }
}
