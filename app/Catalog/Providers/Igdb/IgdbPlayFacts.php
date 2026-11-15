<?php

namespace App\Catalog\Providers\Igdb;

use App\Catalog\Data\LanguageSupport;
use App\Catalog\Data\MultiplayerSupport;
use App\Catalog\Data\ProviderRating;
use App\Catalog\MultiplayerMode;
use App\Support\Payload;

/**
 * How a game is played and received: the languages it speaks, the ways it is
 * played together, the view it is played from, and its scores from critics
 * and from players apart.
 */
final readonly class IgdbPlayFacts
{
    /** The fields these readings need, for the details query. */
    public const FIELDS = 'language_supports.language.locale, language_supports.language_support_type.name, '
        .'multiplayer_modes.onlinecoop, multiplayer_modes.onlinecoopmax, multiplayer_modes.offlinecoop, multiplayer_modes.offlinecoopmax, '
        .'multiplayer_modes.lancoop, multiplayer_modes.campaigncoop, multiplayer_modes.dropin, multiplayer_modes.splitscreen, '
        .'multiplayer_modes.splitscreenonline, multiplayer_modes.onlinemax, multiplayer_modes.offlinemax, '
        .'player_perspectives.name, rating, rating_count, aggregated_rating, aggregated_rating_count';

    /** IGDB language_support_types by name (queried from /v4/language_support_types). */
    private const SUPPORT_TYPES = ['Audio' => 'audio', 'Subtitles' => 'subtitles', 'Interface' => 'interface'];

    /**
     * IGDB multiplayer flags and the player count each one names, if any.
     * "onlinemax"/"offlinemax" alone are the plain multiplayer of that kind.
     */
    private const MODES = [
        'onlinecoop' => [MultiplayerMode::OnlineCoop, 'onlinecoopmax'],
        'offlinecoop' => [MultiplayerMode::LocalCoop, 'offlinecoopmax'],
        'lancoop' => [MultiplayerMode::LanCoop, null],
        'campaigncoop' => [MultiplayerMode::CampaignCoop, null],
        'dropin' => [MultiplayerMode::DropIn, null],
        'splitscreen' => [MultiplayerMode::SplitScreen, null],
        'splitscreenonline' => [MultiplayerMode::SplitScreen, null],
    ];

    /** A count of one is a game played alone; IGDB fills it in on single-player games too. */
    private const TOGETHER = 2;

    /**
     * One entry per language, its kinds of support merged.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<LanguageSupport>
     */
    public function languages(array $data): array
    {
        $kinds = [];

        foreach (Payload::list($data['language_supports'] ?? null) as $support) {
            $locale = Payload::string(Payload::map($support['language'] ?? null), 'locale');
            $type = Payload::string(Payload::map($support['language_support_type'] ?? null), 'name');
            $kind = $type === null ? null : self::SUPPORT_TYPES[$type] ?? null;

            if ($locale !== null && $kind !== null) {
                $kinds[$locale][$kind] = true;
            }
        }

        return array_map(
            fn (string $locale, array $has): LanguageSupport => new LanguageSupport(
                $locale,
                audio: isset($has['audio']),
                subtitles: isset($has['subtitles']),
                interface: isset($has['interface']),
            ),
            array_keys($kinds),
            array_values($kinds),
        );
    }

    /**
     * The ways a game is played together on any of its platforms, each with
     * the most players any platform allows.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<MultiplayerSupport>
     */
    public function multiplayer(array $data): array
    {
        /** @var array<string, int|null> $players mode value => most players named */
        $players = [];

        $add = function (MultiplayerMode $mode, ?int $count) use (&$players): void {
            $count = $count !== null && $count >= self::TOGETHER ? $count : null;
            $known = $players[$mode->value] ?? null;
            $players[$mode->value] = $known === null || ($count !== null && $count > $known) ? $count : $known;
        };

        foreach (Payload::list($data['multiplayer_modes'] ?? null) as $row) {
            foreach (self::MODES as $flag => [$mode, $countKey]) {
                if (($row[$flag] ?? false) === true) {
                    $add($mode, $countKey === null ? null : Payload::int($row, $countKey));
                }
            }

            foreach (['onlinemax' => MultiplayerMode::Online, 'offlinemax' => MultiplayerMode::Local] as $countKey => $mode) {
                $count = Payload::int($row, $countKey);

                if ($count !== null && $count >= self::TOGETHER) {
                    $add($mode, $count);
                }
            }
        }

        $supports = [];

        foreach (MultiplayerMode::cases() as $mode) {
            if (array_key_exists($mode->value, $players)) {
                $supports[] = new MultiplayerSupport($mode, $players[$mode->value]);
            }
        }

        return $supports;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    public function perspectives(array $data): array
    {
        return Payload::names($data['player_perspectives'] ?? null);
    }

    /**
     * The critics' score (IGDB's aggregate of outside reviews), null when no
     * critic rated it.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function critics(array $data): ?ProviderRating
    {
        return $this->rating($data, 'aggregated_rating', 'aggregated_rating_count');
    }

    /**
     * The players' score (IGDB members), null when no player rated it.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function players(array $data): ?ProviderRating
    {
        return $this->rating($data, 'rating', 'rating_count');
    }

    /**
     * IGDB scores run 0–100; the app reads 0–10 everywhere.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function rating(array $data, string $scoreKey, string $countKey): ?ProviderRating
    {
        $score = Payload::float($data, $scoreKey);
        $count = Payload::int($data, $countKey);

        return $score === null || $score <= 0 || $count === null || $count < 1 ? null : new ProviderRating(round($score / 10, 1), $count);
    }
}
