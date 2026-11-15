<?php

namespace Tests\Fakes;

use App\Catalog\MediaKind;
use App\Integrations\Scrobble\Contracts\ScrobbleSource;
use App\Integrations\Scrobble\Data\ScrobbleEvent;
use App\Integrations\Scrobble\ScrobbleEventKind;
use App\Integrations\Scrobble\UnrecognizedPayload;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A source whose payload IS the normalized event, so the pipeline can be
 * exercised end to end without pretending to be anybody's media server.
 * The real sources plug into the same pipeline and change nothing here —
 * that is the contract this fake proves.
 *
 * Two payloads have special meaning: `{"kind": "noise"}` is the
 * not-an-event case every server produces (progress pings, startup
 * notices), and `{"kind": "broken"}` throws, standing in for a payload that
 * claims to be an event and is not.
 */
class FakeScrobbleSource implements ScrobbleSource
{
    public const CODE = 'fake';

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return 'Fake';
    }

    public function description(): string
    {
        return 'A source that exists only in tests.';
    }

    public function parse(Request $request): ?ScrobbleEvent
    {
        $kind = (string) $request->input('kind');

        if ($kind === '') {
            throw new UnrecognizedPayload('No kind.');
        }

        if ($kind === 'noise') {
            return null;
        }

        if ($kind === 'broken') {
            throw new RuntimeException('Unreadable payload.');
        }

        $occurredAt = $request->input('occurred_at');

        return new ScrobbleEvent(
            kind: ScrobbleEventKind::from($kind),
            mediaKind: MediaKind::from((string) $request->input('media_kind')),
            externalIds: array_map('strval', (array) $request->input('ids', [])),
            seriesExternalIds: array_map('strval', (array) $request->input('series_ids', [])),
            title: $request->input('title'),
            year: $request->filled('year') ? (int) $request->input('year') : null,
            seasonNumber: $request->filled('season') ? (int) $request->input('season') : null,
            episodeNumber: $request->filled('episode') ? (int) $request->input('episode') : null,
            progressPercent: $request->filled('percent') ? (float) $request->input('percent') : null,
            rating: $request->filled('rating') ? (int) $request->input('rating') : null,
            occurredAt: is_string($occurredAt) ? CarbonImmutable::parse($occurredAt) : null,
            serverUsername: $request->input('username'),
        );
    }

    public function instructions(): array
    {
        return [];
    }

    public function notice(): ?string
    {
        return null;
    }
}
