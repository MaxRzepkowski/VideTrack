<?php

use App\Catalog\Creators\CompanyMetadata;
use App\Catalog\Data\CompanyDetails;

test("a company's page reads back from the row as it was written", function () {
    $details = new CompanyDetails(
        ref: '420',
        name: 'Example Studios',
        description: 'A film studio.',
        founded: '1993-04-30',
        country: 'US',
        parentName: 'Example Holdings',
        parentRef: '7',
        externalLinks: ['homepage' => 'https://example.com/'],
    );

    $read = CompanyMetadata::fromStored(CompanyMetadata::fromDetails($details)->toStored());

    expect($read->description)->toBe('A film studio.')
        ->and($read->founded)->toBe('1993-04-30')
        ->and($read->country)->toBe('US')
        ->and($read->parentName)->toBe('Example Holdings')
        ->and($read->parentRef)->toBe('7')
        ->and($read->externalLinks)->toBe(['homepage' => 'https://example.com/']);
});

test('a description of nothing but whitespace is not kept', function () {
    $details = new CompanyDetails(ref: '420', name: 'Example Studios', description: "  \n ");

    expect(CompanyMetadata::fromDetails($details)->description)->toBeNull();
});

test('a row with nothing stored, or values of another shape, reads as none', function (?array $stored) {
    $read = CompanyMetadata::fromStored($stored);

    expect($read->description)->toBeNull()
        ->and($read->founded)->toBeNull()
        ->and($read->country)->toBeNull()
        ->and($read->parentName)->toBeNull()
        ->and($read->parentRef)->toBeNull()
        ->and($read->externalLinks)->toBe([]);
})->with([
    'no metadata' => [null],
    'another shape' => [['description' => 12, 'founded' => ['1993'], 'country' => false, 'externalLinks' => 'https://example.com/']],
]);
