<?php

namespace App\Integrations\Scrobble\Contracts;

/**
 * A source that can hand back a payload of its own shape, so the user can
 * try the whole route — address, token, parser, resolver — before touching
 * the server's configuration.
 *
 * Separate from ScrobbleSource because it is a convenience, not a duty: a
 * source whose payloads are whatever the user's own script sends (the generic
 * webhook) has no example to give.
 */
interface ProvidesSamplePayload
{
    /**
     * Request input standing in for one real delivery — the same array the
     * server would post. A title everybody's provider knows, so the dry run
     * ends in a match rather than in a puzzle.
     *
     * @return array<string, mixed>
     */
    public function samplePayload(): array;
}
