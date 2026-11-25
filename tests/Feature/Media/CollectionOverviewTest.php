<?php

use App\Actions\Media\BuildCollectionOverview;
use App\Catalog\Department;
use App\Http\Data\CollectionCredit;
use App\Http\Data\CollectionOverview;
use App\Models\Credit;
use App\Models\ListItem;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\Person;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A collection list holding the given parts, in the order given.
 *
 * @param  list<array<string, mixed>>  $parts  attributes of each part
 * @return array{0: MediaList, 1: list<MediaItem>}
 */
function sagaOf(array $parts, string $ref = '10'): array
{
    $list = MediaList::factory()->create(['user_id' => null, 'system_key' => MediaList::collectionKey('tmdb', $ref), 'is_public' => true]);
    $items = [];

    foreach ($parts as $position => $attributes) {
        $items[] = $part = MediaItem::factory()->movie()->create($attributes);
        ListItem::factory()->create(['list_id' => $list->id, 'media_item_id' => $part->id, 'position' => $position + 1]);
    }

    return [$list, $items];
}

function overviewOf(MediaList $list): ?CollectionOverview
{
    return app(BuildCollectionOverview::class)->execute($list->fresh());
}

test('a list a user typed out has no overview, on the action and on the page', function () {
    $user = User::factory()->create();
    $list = MediaList::factory()->create(['user_id' => $user->id]);
    ListItem::factory()->create(['list_id' => $list->id]);

    expect(overviewOf($list))->toBeNull();

    $this->actingAs($user)
        ->get(listPage($list))
        ->assertInertia(fn (Assert $page) => $page->where('collectionOverview', null));
});

test('money sums the parts that have an amount, and says how many did', function () {
    [$list] = sagaOf([
        ['metadata' => ['budget' => 63_000_000, 'revenue' => 463_517_383]],
        ['metadata' => ['budget' => 150_000_000]],
        ['metadata' => []],
    ]);

    $overview = overviewOf($list);

    expect([$overview?->budget, $overview?->budgetCounted, $overview?->revenue, $overview?->revenueCounted])
        ->toBe([213_000_000, 2, 463_517_383, 1]);
});

test('a saga without a single amount has none, not zero', function () {
    [$list] = sagaOf([['metadata' => []]]);

    expect([overviewOf($list)?->budget, overviewOf($list)?->budgetCounted])->toBe([null, 0]);
});

test('parts out already and parts to come are counted by the rule every page airs by', function () {
    [$list] = sagaOf([
        ['release_date' => '1999-03-31', 'runtime' => 136],
        ['release_date' => today()->subDay()->toDateString(), 'runtime' => 138],
        ['release_date' => today()->addMonth()->toDateString(), 'runtime' => null],
        ['release_date' => null, 'runtime' => null],
    ]);

    $overview = overviewOf($list);

    expect([$overview?->titleCount, $overview?->releasedCount, $overview?->upcomingCount, $overview?->totalRuntime])
        ->toBe([4, 2, 2, 274]);
});

test('what the parts share is gathered once each, the most shared first', function () {
    $studio = fn (string $name, string $ref) => ['name' => $name, 'kind' => 'company', 'ref' => $ref, 'provider' => 'tmdb', 'logoPath' => null];
    [$list] = sagaOf([
        ['metadata' => [
            'studios' => [$studio('Village Roadshow', '79'), $studio('Warner Bros.', '174')],
            'productionCountries' => ['AU', 'US'],
            'originalLanguage' => 'en',
            'certification' => 'R',
            'keywords' => [['name' => 'simulated reality', 'ref' => '4565'], ['name' => 'dystopia', 'ref' => '4458']],
        ]],
        ['metadata' => [
            'studios' => [$studio('Warner Bros.', '174'), $studio('NPV Entertainment', '8147')],
            'productionCountries' => ['US'],
            'originalLanguage' => 'en',
            'certification' => 'R',
            'keywords' => [['name' => 'dystopia', 'ref' => '4458']],
        ]],
        ['metadata' => [
            'studios' => [$studio('Warner Bros.', '174')],
            'productionCountries' => ['US', 'US'],
            'originalLanguage' => 'ja',
            'certification' => 'PG-13',
        ]],
    ]);

    $overview = overviewOf($list);

    expect(array_map(fn ($studio) => $studio->name, $overview?->studios ?? []))->toBe(['Warner Bros.', 'Village Roadshow', 'NPV Entertainment'])
        ->and($overview?->studios[0]->ref)->toBe('174')
        ->and($overview?->productionCountries)->toBe(['US', 'AU'])
        ->and($overview?->originalLanguages)->toBe(['en', 'ja'])
        // Every rating a part carries, not the one most of them do.
        ->and($overview?->certifications)->toBe(['R', 'PG-13'])
        ->and(array_map(fn ($keyword) => $keyword->name, $overview?->keywords ?? []))->toBe(['dystopia', 'simulated reality']);
});

test('the cast gathers a person once, with every character, the most returning first', function () {
    [$list, $parts] = sagaOf([[], [], []]);
    $neo = Person::factory()->create(['name' => 'Keanu Reeves', 'provider_ref' => '6384', 'profile_path' => '/keanu.jpg']);
    $smith = Person::factory()->create(['name' => 'Hugo Weaving', 'provider_ref' => '1331']);
    $oracle = Person::factory()->create(['name' => 'Gloria Foster', 'provider_ref' => '9364']);
    $lana = Person::factory()->create(['name' => 'Lana Wachowski', 'provider_ref' => '9340']);

    foreach ($parts as $part) {
        Credit::factory()->create(['media_item_id' => $part->id, 'person_id' => $neo->id, 'role' => 'Neo', 'order' => 0]);
    }

    Credit::factory()->create(['media_item_id' => $parts[2]->id, 'person_id' => $neo->id, 'role' => 'Thomas Anderson', 'order' => 1]);
    // Billed above Hugo Weaving, in fewer parts: the count comes first.
    Credit::factory()->create(['media_item_id' => $parts[0]->id, 'person_id' => $oracle->id, 'role' => 'Oracle', 'order' => 1]);
    Credit::factory()->create(['media_item_id' => $parts[0]->id, 'person_id' => $smith->id, 'role' => 'Agent Smith', 'order' => 3]);
    Credit::factory()->create(['media_item_id' => $parts[1]->id, 'person_id' => $smith->id, 'role' => 'Agent Smith', 'order' => 2]);
    Credit::factory()->create(['media_item_id' => $parts[0]->id, 'person_id' => $lana->id, 'department' => Department::Directing, 'role' => 'Director']);
    Credit::factory()->create(['media_item_id' => $parts[1]->id, 'person_id' => $lana->id, 'department' => Department::Writing, 'role' => 'Screenplay']);
    Credit::factory()->create(['media_item_id' => $parts[1]->id, 'person_id' => $lana->id, 'department' => Department::Writing, 'role' => 'Story']);
    Credit::factory()->create(['media_item_id' => $parts[1]->id, 'department' => Department::Production, 'role' => 'Producer']);

    $overview = overviewOf($list);
    $summary = fn (CollectionCredit $credit): array => [$credit->name, $credit->department, array_column($credit->roles, 'role'), $credit->titleCount];

    expect(array_map($summary, $overview?->cast ?? []))->toBe([
        ['Keanu Reeves', Department::Acting, ['Neo', 'Thomas Anderson'], 3],
        ['Hugo Weaving', Department::Acting, ['Agent Smith'], 2],
        ['Gloria Foster', Department::Acting, ['Oracle'], 1],
    ])
        ->and($overview?->cast[0]->personRef)->toBe('6384')
        ->and($overview?->cast[0]->profilePath)->toBe('/keanu.jpg')
        // A director who also wrote is one entry per department, as a title's crew is.
        ->and(array_map($summary, $overview?->crew ?? []))->toBe([
            ['Lana Wachowski', Department::Directing, ['Director'], 1],
            ['Lana Wachowski', Department::Writing, ['Screenplay', 'Story'], 1],
        ]);
});

test('the overview reads in the same number of queries however long the saga is', function () {
    $queries = function (int $parts): array {
        [$list, $items] = sagaOf(array_fill(0, $parts, []), (string) $parts);

        foreach ($items as $part) {
            Credit::factory()->count(2)->create(['media_item_id' => $part->id]);
        }

        $list = $list->fresh();

        return queriesRunBy(fn () => app(BuildCollectionOverview::class)->execute($list));
    };

    expectSameQueries($queries(2), $queries(6));
});

test('the collection page carries the overview', function () {
    [$list] = sagaOf([['metadata' => ['budget' => 63_000_000]]]);

    $this->actingAs(User::factory()->create())
        ->get(listPage($list))
        ->assertInertia(fn (Assert $page) => $page
            ->where('collectionOverview.titleCount', 1)
            ->where('collectionOverview.budget', 63_000_000)
            ->where('collectionOverview.cast', []));
});
