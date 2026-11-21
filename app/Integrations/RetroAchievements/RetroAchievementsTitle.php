<?php

namespace App\Integrations\RetroAchievements;

/**
 * A game's title as RetroAchievements writes it, read back into the name a
 * catalogue knows it by. RetroAchievements files a leading article at the
 * end ("Legend of Zelda, The: A Link to the Past"), joins alternative names
 * with " | ", marks what is not a retail release with a tilde tag
 * ("~Unlicensed~ Hi-Leg Fantasy") and a set of extra achievements with a
 * bracket ("[Subset - Bonus]"). A region or a platform in brackets at the
 * end is the title matcher's to read past, as for every game.
 */
final class RetroAchievementsTitle
{
    /** Tags of titles no catalogue holds: fan-made games and changed ones. */
    private const UNCATALOGUED_TAGS = ['hack', 'homebrew'];

    /**
     * The name to search for; null for a title no catalogue holds (a hack, a
     * homebrew game, a subset of another game's achievements).
     */
    public static function searchable(string $title): ?string
    {
        $title = trim($title);

        if (str_contains($title, '[Subset')) {
            return null;
        }

        while (preg_match('/^~([^~]+)~\s*/', $title, $tag) === 1) {
            if (in_array(mb_strtolower(trim($tag[1])), self::UNCATALOGUED_TAGS, true)) {
                return null;
            }

            $title = mb_substr($title, mb_strlen($tag[0]));
        }

        $title = trim(explode(' | ', $title)[0]);
        $title = (string) preg_replace('/^(.+?), (The|A|An)(?=$|:| - )/u', '$2 $1', $title);

        return $title === '' ? null : $title;
    }
}
