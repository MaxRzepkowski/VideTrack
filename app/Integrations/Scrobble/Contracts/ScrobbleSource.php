<?php

namespace App\Integrations\Scrobble\Contracts;

use App\Integrations\Scrobble\Data\ScrobbleEvent;
use App\Integrations\Scrobble\Data\SetupStep;
use App\Integrations\Scrobble\UnrecognizedPayload;
use Illuminate\Http\Request;

/**
 * Everything a scrobble source has to know how to do. Adding Jellyfin, Plex,
 * Tautulli or Komga is writing one of these and registering it — nothing in
 * the ingest pipeline changes, and nothing in the integrations page either:
 * it renders whatever the registry holds.
 */
interface ScrobbleSource
{
    /** Stable code; it appears in the endpoint URL and in MutationSource. */
    public function code(): string;

    /** The product's own name, shown as the card's title and never translated. */
    public function name(): string;

    /** One line on what connecting this gets you, as an i18n key. */
    public function description(): string;

    /**
     * Validates and normalizes one payload.
     *
     * Returns null when the payload names an event we do not act on
     * (Jellyfin posts progress pings, servers post their own startup notices,
     * other accounts' music) — a normal, silent outcome, logged without what
     * was sent: the server's routine traffic carries the household's names.
     * Throws {@see UnrecognizedPayload} when the payload names no event at
     * all, and anything else when it claims to be an event and is broken; the
     * delivery is then logged as an error.
     */
    public function parse(Request $request): ?ScrobbleEvent;

    /**
     * Setup steps for the user — rendered next to the endpoint in settings.
     * An empty array means the source needs no explaining.
     *
     * @return list<SetupStep>
     */
    public function instructions(): array;

    /**
     * What the user has to know before trying, as an i18n key: Plex webhooks
     * need a Plex Pass, Emby's need Premiere. Null when there is no catch.
     */
    public function notice(): ?string;
}
