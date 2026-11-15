<?php

namespace App\Catalog\Providers\Igdb;

use App\Catalog\Data\AgeRatingData;
use App\Support\Payload;

/** Every board's age rating of a game, with the content behind it. */
final readonly class IgdbAgeRatings
{
    /** The fields this reading needs, for the details query. */
    public const FIELDS = 'age_ratings.organization.name, age_ratings.rating_category.rating, '
        .'age_ratings.rating_content_descriptions.description, age_ratings.synopsis';

    /** IGDB's organization names where the board itself goes by another. */
    private const BOARD_NAMES = ['CLASS_IND' => 'ClassInd'];

    /**
     * One per board, in IGDB's order.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<AgeRatingData>
     */
    public function of(array $data): array
    {
        $ratings = [];

        foreach (Payload::list($data['age_ratings'] ?? null) as $entry) {
            $board = Payload::string(Payload::map($entry['organization'] ?? null), 'name');
            $board = $board === null ? null : self::BOARD_NAMES[$board] ?? $board;
            $rating = Payload::string(Payload::map($entry['rating_category'] ?? null), 'rating');

            if ($board === null || $rating === null || isset($ratings[$board])) {
                continue;
            }

            $descriptors = [];

            foreach (Payload::list($entry['rating_content_descriptions'] ?? null) as $descriptor) {
                $text = Payload::string($descriptor, 'description');

                if ($text !== null && ! in_array($text, $descriptors, true)) {
                    $descriptors[] = $text;
                }
            }

            $ratings[$board] = new AgeRatingData($board, $rating, $descriptors, Payload::string($entry, 'synopsis'));
        }

        return array_values($ratings);
    }

    /**
     * The same as the chip reads them: board => rating (PEGI => 18).
     *
     * @param  list<AgeRatingData>  $ratings
     * @return array<string, string>
     */
    public static function byBoard(array $ratings): array
    {
        $byBoard = [];

        foreach ($ratings as $rating) {
            $byBoard[$rating->board] = $rating->rating;
        }

        return $byBoard;
    }
}
