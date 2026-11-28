<?php

use App\Integrations\Anime\KitsuDocument;
use App\Integrations\Anime\ListMedium;

/** @return array<string, mixed> */
function kitsuAnime(string ...$mappingIds): array
{
    return ['type' => 'anime', 'id' => '7442', 'relationships' => ['mappings' => ['data' => array_map(fn (string $id) => ['type' => 'mappings', 'id' => $id], $mappingIds)]]];
}

test('a title is the MyAnimeList entry its included mapping for the medium names', function () {
    $document = KitsuDocument::of(['data' => kitsuAnime('1', '2', '3'), 'included' => [
        ['type' => 'mappings', 'id' => '1', 'attributes' => ['externalSite' => 'anidb', 'externalId' => '9541']],
        // Some mappings carry junk in place of an id.
        ['type' => 'mappings', 'id' => '2', 'attributes' => ['externalSite' => 'myanimelist/anime', 'externalId' => 'anime']],
        ['type' => 'mappings', 'id' => '3', 'attributes' => ['externalSite' => 'myanimelist/anime', 'externalId' => '16498']],
    ]]);

    expect($document->malId(kitsuAnime('1', '2', '3'), ListMedium::Anime))->toBe(16498)
        ->and($document->malId(kitsuAnime('1', '2', '3'), ListMedium::Manga))->toBeNull()
        ->and($document->included('mappings', '1'))->not->toBeNull()
        ->and($document->included('mappings', '9'))->toBeNull();
});

test('a mapping the answer did not include names nothing', function () {
    expect(KitsuDocument::of(['data' => kitsuAnime('5')])->malId(kitsuAnime('5'), ListMedium::Anime))->toBeNull();
});
