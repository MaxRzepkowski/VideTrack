<?php

namespace App\Http\Mcp;

use App\Catalog\Data\MediaSummary;
use App\Support\Payload;
use UnexpectedValueException;

/**
 * An API v1 answer cut down for a language model, whose context is the cost
 * of every field: no pictures (an assistant cannot look at them), nothing a
 * page only draws with, nothing that is null, false, empty or at the value
 * of an untouched position, and no state said twice. The server's
 * instructions say so, so an absent flag reads as false and an absent value
 * as none.
 */
final class Compact
{
    /** The pictures of a card, a person, a company or an event: each as a path and as an address. */
    private const PICTURES = [
        'posterPath', 'posterUrl', 'backdropPath', 'backdropUrl', 'profilePath', 'profileUrl',
        'logoPath', 'logoUrl', 'imagePath', 'imageUrl', 'photoPath', 'photoUrl', 'showPoster', 'showBackdrop',
    ];

    /** What a page draws a card's bar and badges from, and an assistant has no use for. */
    private const DRAWING = [
        'progression', 'followSource', 'statusInherited', 'position', 'remainingMinutes', 'watchedMinutes',
    ];

    /** A field at the value of a position nobody touched says nothing. */
    private const UNTOUCHED = ['plays' => 0, 'cycle' => 1, 'completedCycles' => 0, 'skippedEpisodes' => 0];

    public static function of(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $kept = [];

        foreach ($value as $key => $entry) {
            if (in_array($key, self::PICTURES, true) || in_array($key, self::DRAWING, true)) {
                continue;
            }

            if (is_string($key) && array_key_exists($key, self::UNTOUCHED) && $entry === self::UNTOUCHED[$key]) {
                continue;
            }

            $entry = self::of($entry);

            if ($entry === null || $entry === false || $entry === []) {
                continue;
            }

            $kept[$key] = $entry;
        }

        return array_is_list($value) ? array_values($kept) : $kept;
    }

    /**
     * A write's answer without the written title's state twice: the card
     * under `data` carries it, so `states` keeps only the others the write
     * moved (an episode's season and show).
     *
     * @param  array<array-key, mixed>  $answer
     * @return array<array-key, mixed>
     */
    public static function withoutRepeatedState(array $answer): array
    {
        $card = $answer['data'] ?? null;
        $states = $answer['states'] ?? null;

        if (! is_array($card) || ! is_array($states) || ! array_key_exists('state', $card)) {
            return $answer;
        }

        $type = Payload::string($card, 'type') ?? throw new UnexpectedValueException('A card with a state but no type.');
        $ref = Payload::string($card, 'ref') ?? throw new UnexpectedValueException('A card with a state but no ref.');
        unset($states[MediaSummary::keyOf($type, $ref)]);

        return [...$answer, 'states' => $states];
    }
}
