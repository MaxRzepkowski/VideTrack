<?php

namespace App\Catalog\Providers\Igdb;

use App\Catalog\Data\StoreLink;
use App\Catalog\GameStore;
use App\Support\Payload;

/**
 * Where a game is sold or played by subscription: IGDB's store entries
 * (`external_games`, each with the game's own page there), filled in from the
 * game's websites for a store those leave out (Nintendo has no entry).
 */
final readonly class IgdbStores
{
    /** The fields this reading needs beside `websites`, for the details query. */
    public const FIELDS = 'external_games.external_game_source.name, external_games.url';

    /**
     * IGDB external_game_sources by name (queried from /v4/external_game_sources).
     * The rest are not shops (Twitch, YouTube, GiantBomb) or list a game many
     * times over (Amazon).
     */
    private const SOURCES = [
        'Steam' => GameStore::Steam,
        'GOG' => GameStore::Gog,
        'Epic Games Store' => GameStore::EpicGames,
        'Playstation Store US' => GameStore::PlayStation,
        'Microsoft' => GameStore::Xbox,
        'Xbox Marketplace' => GameStore::Xbox,
        'Itchio' => GameStore::Itch,
        'Apple' => GameStore::AppStore,
        'Android' => GameStore::GooglePlay,
        'Xbox Game Pass Ultimate Cloud' => GameStore::GamePass,
        'Utomik' => GameStore::Utomik,
    ];

    /** IGDB website_types that are a store's page (queried from /v4/website_types). */
    private const WEBSITE_TYPES = [
        13 => GameStore::Steam,
        17 => GameStore::Gog,
        16 => GameStore::EpicGames,
        23 => GameStore::PlayStation,
        22 => GameStore::Xbox,
        24 => GameStore::Nintendo,
        15 => GameStore::Itch,
        10 => GameStore::AppStore,
        11 => GameStore::AppStore,
        12 => GameStore::GooglePlay,
    ];

    /**
     * One link per store, the store's own entry first, in the stores' order.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<StoreLink>
     */
    public function of(array $data): array
    {
        $urls = [];

        foreach (Payload::list($data['external_games'] ?? null) as $entry) {
            $name = Payload::string(Payload::map($entry['external_game_source'] ?? null), 'name');
            $store = $name === null ? null : self::SOURCES[$name] ?? null;
            $url = Payload::string($entry, 'url');

            if ($store !== null && $url !== null && $this->isWeb($url)) {
                $urls[$store->value] ??= $url;
            }
        }

        foreach (Payload::list($data['websites'] ?? null) as $website) {
            $store = self::WEBSITE_TYPES[Payload::int($website, 'type') ?? -1] ?? null;
            $url = Payload::string($website, 'url');

            if ($store !== null && $url !== null && $this->isWeb($url)) {
                $urls[$store->value] ??= $url;
            }
        }

        $links = [];

        foreach (GameStore::cases() as $store) {
            if (isset($urls[$store->value])) {
                $links[] = new StoreLink($store, $urls[$store->value]);
            }
        }

        return $links;
    }

    /**
     * The website keys a store link stands for, to keep them out of the game's other links.
     *
     * @return list<string>
     */
    public static function websiteKeys(): array
    {
        return array_map(fn (GameStore $store): string => $store->value, GameStore::cases());
    }

    private function isWeb(string $url): bool
    {
        return str_starts_with($url, 'https://') || str_starts_with($url, 'http://');
    }
}
