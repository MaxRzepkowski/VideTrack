<?php

use App\Catalog\Creators\CompanyRecords;
use App\Catalog\Creators\CreatorKind;
use App\Catalog\Creators\PersonRecords;
use App\Catalog\Data\CompanyDetails;
use App\Catalog\Data\PersonDetails;
use App\Models\Company;
use App\Models\Person;
use Illuminate\Support\Facades\Http;

test('a person page fills every column, not just the name', function () {
    Http::fake(['*/person/6384*' => Http::response(jsonFixture('tmdb/person'))]);

    $person = app(PersonRecords::class)->ensure('tmdb', '6384', 'en');

    expect($person->name)->toBe('Keanu Reeves')
        ->and($person->biography)->toBe('Keanu Charles Reeves is a Canadian actor.')
        ->and($person->birthday?->toDateString())->toBe('1964-09-02')
        ->and($person->deathday)->toBeNull()
        ->and($person->refreshed_at)->not->toBeNull()
        ->and($person->metadata['knownForDepartment'])->toBe('acting')
        ->and($person->metadata['placeOfBirth'])->toBe('Beirut, Lebanon')
        ->and($person->metadata['alsoKnownAs'])->toBeArray();
});

test('a second page fetch updates the existing row instead of adding one', function () {
    Http::fake(['*/person/6384*' => Http::response(jsonFixture('tmdb/person'))]);

    $first = app(PersonRecords::class)->ensure('tmdb', '6384', 'en');

    $details = new PersonDetails(
        ref: '6384',
        name: 'Keanu Charles Reeves',
        profilePath: null,
        biography: null,
        placeOfBirth: 'Beirut',
    );

    $second = app(PersonRecords::class)->fromDetails('tmdb', '6384', $details);

    expect(Person::count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->name)->toBe('Keanu Charles Reeves')
        // A payload without a portrait or a biography does not erase the ones
        // an earlier fetch stored.
        ->and($second->profile_path)->toBe('/keanu.jpg')
        ->and($second->biography)->toBe('Keanu Charles Reeves is a Canadian actor.')
        ->and($second->metadata['placeOfBirth'])->toBe('Beirut')
        // Keys this payload says nothing about survive the merge.
        ->and($second->metadata['knownForDepartment'])->toBe('acting');
});

test('a company page fills its metadata', function () {
    $company = app(CompanyRecords::class)->fromDetails('igdb', '51', CreatorKind::Company, new CompanyDetails(
        ref: '51',
        name: 'Rockstar North',
        logoPath: '/rockstar.png',
        description: 'Scottish video game developer.',
        founded: '1987-01-01',
        country: 'GB',
        parentName: 'Take-Two Interactive',
        parentRef: '52',
        externalLinks: ['Homepage' => 'https://www.rockstarnorth.com'],
    ));

    expect(Company::count())->toBe(1)
        ->and($company->name)->toBe('Rockstar North')
        ->and($company->logo_path)->toBe('/rockstar.png')
        ->and($company->refreshed_at)->not->toBeNull()
        ->and($company->metadata['description'])->toBe('Scottish video game developer.')
        ->and($company->metadata['founded'])->toBe('1987-01-01')
        ->and($company->metadata['country'])->toBe('GB')
        ->and($company->metadata['parentName'])->toBe('Take-Two Interactive')
        ->and($company->metadata['parentRef'])->toBe('52')
        ->and($company->metadata['externalLinks'])->toBe(['Homepage' => 'https://www.rockstarnorth.com']);
});

test('the same company under a different kind keeps its own row', function () {
    $details = new CompanyDetails(ref: '49', name: 'HBO');

    app(CompanyRecords::class)->fromDetails('tmdb', '49', CreatorKind::Company, $details);
    app(CompanyRecords::class)->fromDetails('tmdb', '49', CreatorKind::Network, $details);

    expect(Company::count())->toBe(2);
});
