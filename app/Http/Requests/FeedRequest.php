<?php

namespace App\Http\Requests;

use App\Catalog\MediaTypeRegistry;
use App\Tracking\Surfaces\FeedFilter;
use App\Tracking\Surfaces\FeedScope;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A subscription feed's variant from its address: `types` (root type codes,
 * comma-separated) and `scope`. Never a validation error: the feed is read by
 * someone else's app, which cannot show one, so a value that makes no sense
 * here (a type switched off, a typo) falls back to the default.
 */
class FeedRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function feedFilter(FeedScope $defaultScope): FeedFilter
    {
        $types = $this->query('types');
        $scope = $this->query('scope');

        return new FeedFilter(
            // The registry's order whatever the address's: equal variants are one cache entry.
            types: is_string($types) ? $this->rootTypes(explode(',', $types)) : [],
            scope: (is_string($scope) ? FeedScope::tryFrom($scope) : null) ?? $defaultScope,
        );
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function rootTypes(array $codes): array
    {
        $registry = app(MediaTypeRegistry::class);

        return array_values(array_intersect($registry->ordered(array_map(trim(...), $codes)), $registry->rootCodes()));
    }
}
