<?php

namespace App\Catalog\Creators;

use App\Enums\FollowMode;
use App\Models\Company;
use App\Models\Follow;
use App\Models\MediaList;
use App\Models\Person;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * The stored row of a creator: a person, a company (studio, publisher or
 * network, told apart by `kind`) or the system list mirroring a provider
 * collection. A creator gets a row on its first own interaction; one nobody
 * touched has none, which is not an error.
 */
final readonly class Creators
{
    /**
     * Every person, or every company (networks included), somebody follows —
     * never the whole table.
     *
     * @return Collection<int, Person>|Collection<int, Company>
     */
    public function followed(CreatorTable $table): Collection
    {
        return match ($table) {
            CreatorTable::People => Person::query()->whereIn('id', $this->followedIds('person_id'))->get(),
            CreatorTable::Companies => Company::query()->whereIn('id', $this->followedIds('company_id'))->get(),
        };
    }

    /**
     * @return Builder<Follow>
     */
    private function followedIds(string $column): Builder
    {
        return Follow::query()->whereNotNull($column)->where('mode', FollowMode::Follow->value)->select($column);
    }

    /**
     * The row of a person or company named in an imported list: the name in
     * the file, the rest left to the next refresh — an import never asks a
     * provider per entry.
     */
    public function firstOrCreateNamed(CreatorRef $creator, string $name): Person|Company
    {
        return match ($creator->kind) {
            CreatorKind::Person => Person::query()->firstOrCreate(['provider' => $creator->provider, 'provider_ref' => $creator->ref], ['name' => $name]),
            CreatorKind::Company, CreatorKind::Network => Company::query()->firstOrCreate(
                ['provider' => $creator->provider, 'kind' => $creator->kind->value, 'provider_ref' => $creator->ref],
                ['name' => $name],
            ),
            CreatorKind::Collection => throw new LogicException('A collection is never imported by name.'),
        };
    }

    /**
     * The stored rows of many creators at once, keyed by {@see CreatorRef::key()}.
     * A creator without a row is simply missing.
     *
     * @param  iterable<CreatorRef>  $creators
     * @return array<string, Person|Company|MediaList>
     */
    public function findMany(iterable $creators): array
    {
        /** @var array<string, array<string, list<string>>> $wanted kind => provider => refs */
        $wanted = [];

        foreach ($creators as $creator) {
            $wanted[$creator->kind->value][$creator->provider][] = $creator->ref;
        }

        $rows = [];

        foreach ($wanted as $kind => $byProvider) {
            $kind = CreatorKind::from($kind);

            foreach ($this->rowsOf($kind, $byProvider) as $row) {
                $rows[$this->refOf($row)->key()] = $row;
            }
        }

        return $rows;
    }

    public function find(CreatorRef $creator): Person|Company|MediaList|null
    {
        return match ($creator->kind) {
            CreatorKind::Person => Person::query()
                ->where('provider', $creator->provider)
                ->where('provider_ref', $creator->ref)
                ->first(),
            CreatorKind::Company, CreatorKind::Network => Company::query()
                ->where('provider', $creator->provider)
                ->where('kind', $creator->kind->value)
                ->where('provider_ref', $creator->ref)
                ->first(),
            CreatorKind::Collection => MediaList::query()
                ->whereNull('user_id')
                ->where('system_key', MediaList::collectionKey($creator->provider, $creator->ref))
                ->first(),
        };
    }

    /**
     * @param  array<string, list<string>>  $byProvider
     * @return iterable<Person|Company|MediaList>
     */
    private function rowsOf(CreatorKind $kind, array $byProvider): iterable
    {
        if ($kind === CreatorKind::Collection) {
            $keys = [];

            foreach ($byProvider as $provider => $refs) {
                foreach ($refs as $ref) {
                    $keys[] = MediaList::collectionKey($provider, $ref);
                }
            }

            return MediaList::query()->whereNull('user_id')->whereIn('system_key', $keys)->get();
        }

        $query = $kind === CreatorKind::Person
            ? Person::query()
            : Company::query()->where('kind', $kind->value);

        return $query
            ->where(function (Builder $any) use ($byProvider): void {
                foreach ($byProvider as $provider => $refs) {
                    $any->orWhere(function ($one) use ($provider, $refs): void {
                        $one->where('provider', $provider)->whereIn('provider_ref', $refs);
                    });
                }
            })
            ->get();
    }

    /** The kind a stored creator row is. */
    public function kindOf(Person|Company|MediaList $row): CreatorKind
    {
        return match (true) {
            $row instanceof Person => CreatorKind::Person,
            $row instanceof Company => CreatorKind::from($row->kind),
            $row instanceof MediaList => CreatorKind::Collection,
        };
    }

    private function refOf(Person|Company|MediaList $row): CreatorRef
    {
        if ($row instanceof MediaList) {
            // Rows come from the collections query, so the source is there.
            $source = $row->collectionSource() ?? throw new LogicException("List [{$row->id}] is not a collection.");

            return new CreatorRef(CreatorKind::Collection, $source['provider'], $source['ref']);
        }

        return new CreatorRef($this->kindOf($row), $row->provider, $row->provider_ref);
    }
}
