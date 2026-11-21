<?php

namespace App\Catalog;

use App\Catalog\Contracts\SupportsCollectionSearch;
use App\Catalog\Contracts\SupportsCompanySearch;
use App\Catalog\Contracts\SupportsPeopleSearch;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\SearchScopes;

/**
 * One search against exactly one scope — a provider's titles, or the people,
 * collections or companies a capable provider adds. Shared by the search page
 * and the API so both offer the same scopes. One scope is searched at a time,
 * so a provider that cannot answer fails the search: the caller says so
 * instead of reporting that nothing matched.
 */
final readonly class CatalogSearch
{
    public function __construct(
        private MediaTypeRegistry $types,
        private ProviderRegistry $providers,
    ) {}

    public function scopes(): SearchScopes
    {
        $scopes = [];

        foreach ($this->types->searchableCodes() as $code) {
            $scopes[$this->types->get($code)->provider][] = $code;
        }

        foreach (EntitySearchScope::cases() as $scope) {
            if ($this->providers->firstSupporting($scope->contract()) !== null) {
                $scopes[$scope->value] = [$scope->value];
            }
        }

        return new SearchScopes($scopes);
    }

    /**
     * `$scope` is one of scopes(); an empty query finds nothing. The results
     * go as far as the viewer lets the scope's provider go.
     *
     * @return list<MediaSummary>
     *
     * @throws ProviderException|ProviderRejected the scope's provider could not answer
     */
    public function search(string $scope, string $query, string $locale, MatureChoices $mature): array
    {
        if ($query === '') {
            return [];
        }

        $entity = EntitySearchScope::tryFrom($scope);

        try {
            return $entity === null
                ? $this->providers->get($scope)->search($query, $locale, $mature->for($scope))
                : $this->searchEntities($entity, $query, $locale, $mature);
        } catch (ItemNotFoundException) {
            // Nothing by that name at the provider is an answer, not a failure.
            return [];
        }
    }

    /**
     * @return list<MediaSummary>
     */
    private function searchEntities(EntitySearchScope $scope, string $query, string $locale, MatureChoices $mature): array
    {
        $provider = $this->providers->firstSupporting($scope->contract());

        return match (true) {
            $scope === EntitySearchScope::Person && $provider instanceof SupportsPeopleSearch => $provider->searchPeople($query, $locale, $mature->for($provider->code())),
            $scope === EntitySearchScope::Collection && $provider instanceof SupportsCollectionSearch => $provider->searchCollections($query, $locale),
            $scope === EntitySearchScope::Company && $provider instanceof SupportsCompanySearch => $provider->searchCompanies($query, $locale),
            default => [],
        };
    }
}
