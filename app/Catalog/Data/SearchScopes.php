<?php

namespace App\Catalog\Data;

use App\Catalog\EntitySearchScope;

/**
 * The scopes a search can run in on this instance, in offer order: each title
 * provider (its searchable types together), then the entity scopes some
 * provider can serve. There is no mixed "all" scope: every module on the
 * instance would stretch the wait for every keystroke.
 */
final readonly class SearchScopes
{
    /**
     * @param  array<string, list<string>>  $scopes  scope key => type codes (an entity scope: itself)
     */
    public function __construct(public array $scopes) {}

    /**
     * The scope to actually search: the requested one when it exists, the
     * first offered one otherwise (an empty or stale scope is not an error).
     * Null only on an instance with nothing to search.
     */
    public function resolve(string $requested): ?string
    {
        return array_key_exists($requested, $this->scopes) ? $requested : array_key_first($this->scopes);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->scopes);
    }

    /** @return list<string> the type codes a scope covers */
    public function types(string $scope): array
    {
        return $this->scopes[$scope] ?? [];
    }

    public static function isEntity(?string $scope): bool
    {
        return $scope !== null && EntitySearchScope::tryFrom($scope) !== null;
    }
}
