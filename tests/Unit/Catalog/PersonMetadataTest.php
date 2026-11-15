<?php

use App\Catalog\Creators\PersonMetadata;
use App\Catalog\Data\PersonDetails;
use App\Catalog\Department;

test("a person's page reads back from the row as it was written", function () {
    $details = new PersonDetails(
        ref: '6384',
        name: 'Keanu Reeves',
        knownForDepartment: Department::Acting,
        placeOfBirth: 'Beirut, Lebanon',
        origin: 'Canada',
        alsoKnownAs: ['K. Reeves'],
        externalLinks: ['homepage' => 'https://example.com/'],
    );

    $read = PersonMetadata::fromStored(PersonMetadata::fromDetails($details)->toStored());

    expect($read->knownForDepartment)->toBe(Department::Acting)
        ->and($read->placeOfBirth)->toBe('Beirut, Lebanon')
        ->and($read->origin)->toBe('Canada')
        ->and($read->alsoKnownAs)->toBe(['K. Reeves'])
        ->and($read->externalLinks)->toBe(['homepage' => 'https://example.com/']);
});

test('a row with nothing stored, or values of another shape, reads as none', function (?array $stored) {
    $read = PersonMetadata::fromStored($stored);

    expect($read->knownForDepartment)->toBeNull()
        ->and($read->placeOfBirth)->toBeNull()
        ->and($read->origin)->toBeNull()
        ->and($read->alsoKnownAs)->toBe([])
        ->and($read->externalLinks)->toBe([]);
})->with([
    'no metadata' => [null],
    'another shape' => [['knownForDepartment' => 'juggling', 'placeOfBirth' => 12, 'alsoKnownAs' => 'K. Reeves', 'externalLinks' => 'https://example.com/']],
]);
