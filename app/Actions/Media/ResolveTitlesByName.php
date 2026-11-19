<?php

namespace App\Actions\Media;

use App\Catalog\Data\TitleLookup;
use App\Catalog\MediaKind;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRejected;
use App\Integrations\Import\Data\TitleQuery;
use App\Integrations\Import\TitleMatcher;

/**
 * Many titles by name at once, placed by the rule the imports place titles
 * by: the very title (or one of its other names), a year either way. One
 * catalogue that cannot answer leaves its titles unknown, not the others.
 */
final readonly class ResolveTitlesByName
{
    public function __construct(private TitleMatcher $matcher) {}

    /**
     * @param  list<array{kind: MediaKind, query: TitleQuery}>  $lookups
     * @return list<TitleLookup>
     */
    public function execute(array $lookups, string $locale): array
    {
        $found = [];

        foreach ($lookups as ['kind' => $kind, 'query' => $query]) {
            try {
                $found[] = new TitleLookup($kind, $query->title, $query->year, $this->matcher->find($kind, $query, $locale));
            } catch (ProviderException|ProviderRejected $e) {
                report($e);
                $found[] = new TitleLookup($kind, $query->title, $query->year, null, unavailable: true);
            }
        }

        return $found;
    }
}
