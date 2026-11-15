<?php

/**
 * A captured response or export, decoded, by its path under tests/Fixtures
 * without the extension: jsonFixture('tmdb/movie'),
 * jsonFixture('scrobble/plex/play').
 *
 * @return array<string, mixed>
 */
function jsonFixture(string $path): array
{
    return json_decode((string) file_get_contents(fixture("{$path}.json")), true);
}
