<?php

namespace App\Integrations\Scrobble\Concerns;

use App\Catalog\ExternalIdSource;
use App\Integrations\EpisodeLabel;
use App\Support\Payload;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The small conversions every media server needs and none of them agree on:
 * a boolean that arrives as the string "True", a position counted in ticks,
 * a bag of provider ids capitalized differently in each product.
 *
 * All of them are forgiving by design. A field a server renamed between
 * versions comes back as null, the event survives with one fact fewer, and
 * the delivery log says what could not be placed — which beats refusing the
 * payload outright.
 */
trait ReadsPlaybackPayloads
{
    /**
     * The payload as an array, whatever the sender called its content type.
     *
     * Jellyfin's Webhook plugin posts JSON as `text/plain` unless the user
     * adds a header by hand, and nothing in the framework parses a body it
     * was told is prose — `$request->all()` comes back empty and the event
     * evaporates. So: the parsed input when there is any, the raw body read
     * as JSON when there is not.
     *
     * @return array<array-key, mixed>
     */
    protected function readPayload(Request $request): array
    {
        $input = $request->all();

        if ($input !== []) {
            return $input;
        }

        $decoded = json_decode((string) $request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The first of several spellings that carries anything — media servers
     * move fields between versions and between "templated" and "raw" modes.
     *
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $paths
     */
    protected function field(array $payload, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** "True", "true", true, 1 — servers send every one of these. */
    protected function truthy(mixed $value): bool
    {
        return in_array(
            is_string($value) ? mb_strtolower(trim($value)) : $value,
            ['true', '1', 1, true],
            true,
        );
    }

    protected function number(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /** The text in lower case, for matching a server's own words (`Episode`, `episode`). */
    protected function lowercase(mixed $value): ?string
    {
        $text = $this->text($value);

        return $text === null ? null : mb_strtolower($text);
    }

    protected function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * How far in it got, as a percentage — null unless both ends are known,
     * because a position without a runtime says nothing about completion.
     */
    protected function percentFromTicks(mixed $position, mixed $runtime): ?float
    {
        $position = $this->number($position);
        $runtime = $this->number($runtime);

        if ($position === null || $runtime === null || $runtime <= 0) {
            return null;
        }

        return min(100.0, round($position / $runtime * 100, 2));
    }

    /**
     * Provider ids as the resolver speaks them: lowercase source names,
     * string values, nothing empty. "TmdbCollection" and friends are dropped
     * — an id for something other than this item is worse than no id.
     *
     * @param  mixed  $raw  the server's own dictionary
     * @return array<string, string>
     */
    protected function providerIds(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $known = ['tmdb', ExternalIdSource::Imdb->value, ExternalIdSource::Tvdb->value];
        $ids = [];

        foreach ($raw as $source => $id) {
            $source = mb_strtolower(trim((string) $source));
            $id = $this->text($id);

            if ($id === null || ! in_array($source, $known, true)) {
                continue;
            }

            $ids[$source] = $id;
        }

        return $ids;
    }

    /**
     * Provider ids gathered from several places at once, first answer per
     * catalogue winning.
     *
     * Not `field()`, which stops at the first place that holds anything: a
     * template renders absent ids as a dictionary of empty strings, and that
     * dictionary is "something" while naming nothing. The shapes have to be
     * merged, not chosen between.
     *
     * @return array<string, string>
     */
    protected function mergeProviderIds(mixed ...$sources): array
    {
        $ids = [];

        foreach ($sources as $source) {
            foreach ($this->providerIds($source) as $catalogue => $id) {
                $ids[$catalogue] ??= $id;
            }
        }

        return $ids;
    }

    /** The server's clock, when it sends one and we can read it. */
    protected function moment(mixed $value): ?CarbonImmutable
    {
        // A timestamp we cannot read is not worth failing a viewing over;
        // the pipeline falls back to "now".
        return Payload::instant($this->text($value));
    }

    /**
     * What the delivery log calls an episode. The show alone would read as
     * the same line for every episode of a binge.
     */
    protected function episodeLabel(?string $series, ?int $season, ?int $number): ?string
    {
        return $series === null ? null : EpisodeLabel::of($series, $season, $number);
    }
}
