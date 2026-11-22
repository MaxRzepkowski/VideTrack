<?php

namespace App\Catalog;

use App\Catalog\Data\MediaSummary;
use App\Models\CatalogEventTitle;
use App\Models\CatalogSetTitle;
use App\Models\FranchiseMember;
use App\Models\RelatedTitle;
use App\Support\Payload;

/**
 * A title's card kept beside a reference to it, for the tables that point at
 * titles nobody has stored (a set's members, a title's ties, a franchise's
 * members, an event's titles). What a row is found and filtered by has columns of its own; the
 * rest of the card rides in one JSON column.
 */
final readonly class CardSnapshot
{
    /**
     * The columns every such table has, from the card.
     *
     * @return array{type: string, provider: string, ref: string, title: string, release_date: string|null, card: array<string, mixed>}
     */
    public static function columns(MediaSummary $summary): array
    {
        return [
            'type' => $summary->type,
            'provider' => $summary->provider,
            'ref' => $summary->ref,
            'title' => $summary->title,
            'release_date' => $summary->releaseDate,
            'card' => [
                'year' => $summary->year,
                'posterPath' => $summary->posterPath,
                'backdropPath' => $summary->backdropPath,
                'communityRating' => $summary->communityRating,
            ],
        ];
    }

    /** The card back from a row of one of those tables. */
    public static function of(CatalogSetTitle|RelatedTitle|FranchiseMember|CatalogEventTitle $row): MediaSummary
    {
        $rating = $row->card['communityRating'] ?? null;

        return new MediaSummary(
            type: $row->type,
            ref: $row->ref,
            provider: $row->provider,
            title: $row->title,
            year: Payload::positiveInt($row->card, 'year'),
            posterPath: Payload::string($row->card, 'posterPath'),
            backdropPath: Payload::string($row->card, 'backdropPath'),
            communityRating: is_numeric($rating) ? (float) $rating : null,
            releaseDate: $row->release_date?->toDateString(),
            votes: $row->votes,
        );
    }
}
