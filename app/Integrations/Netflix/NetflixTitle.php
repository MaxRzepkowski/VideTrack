<?php

namespace App\Integrations\Netflix;

/**
 * A title as Netflix's viewing activity writes it, in the language of the
 * account: a film's name, or an episode as "Show: Season 1: Episode title".
 * The season is told by its word in that language; a series of one season
 * may say so instead ("Limited Series"). A part after the season is the
 * episode's title, which may itself hold a colon. A season word with no
 * part after it is a film's own ("Kill Bill: Volume 1").
 */
final readonly class NetflixTitle
{
    /** The words a season goes by, with its number after them. */
    private const SEASONS = [
        'Season', 'Part', 'Volume', 'Series', 'Chapter',
        'Sezon', 'Część', 'Seria',
        'Staffel', 'Teil',
        'Temporada', 'Parte',
        'Saison', 'Partie',
    ];

    /** What a series of one season is called instead. */
    private const SINGLE_SEASONS = [
        'Limited Series', 'Miniseries', 'Mini-Series',
        'Miniserial', 'Serial limitowany',
        'Miniserie', 'Limitierte Serie',
        'Serie limitada',
        'Minisérie', 'Mini-série', 'Série limitée',
    ];

    /** The words an episode goes by when its title is only its number. */
    private const EPISODES = ['Episode', 'Odcinek', 'Folge', 'Episodio', 'Épisode'];

    private function __construct(
        public string $title,
        public ?int $season = null,
        public ?string $episodeTitle = null,
        public ?int $episode = null,
    ) {}

    public static function read(string $value): self
    {
        $parts = array_map('trim', explode(': ', trim($value)));

        for ($at = 1; $at < count($parts) - 1; $at++) {
            $season = self::season($parts[$at]);

            if ($season !== null) {
                $episodeTitle = implode(': ', array_slice($parts, $at + 1));

                return new self(implode(': ', array_slice($parts, 0, $at)), $season, $episodeTitle, self::number($episodeTitle));
            }
        }

        return new self(trim($value));
    }

    public function isEpisode(): bool
    {
        return $this->season !== null;
    }

    private static function season(string $part): ?int
    {
        foreach (self::SINGLE_SEASONS as $word) {
            if (mb_strtolower($part) === mb_strtolower($word)) {
                return 1;
            }
        }

        return self::numbered($part, self::SEASONS);
    }

    /** The number an episode is titled by alone ("Episode 4"). */
    private static function number(string $title): ?int
    {
        return self::numbered($title, self::EPISODES);
    }

    /**
     * @param  list<string>  $words
     */
    private static function numbered(string $part, array $words): ?int
    {
        $alternatives = implode('|', array_map(fn (string $word): string => preg_quote($word, '/'), $words));

        return preg_match("/^(?:{$alternatives})\\s+(\\d+)$/iu", $part, $match) === 1 ? (int) $match[1] : null;
    }
}
