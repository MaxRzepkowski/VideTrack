<?php

namespace App\Integrations\Steam;

use App\Catalog\ExternalIdSource;
use App\Catalog\MediaKind;
use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Contracts\ReadsCredentials;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportManifest;
use App\Integrations\Import\Data\ImportMark;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ManifestCategory;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Data\SetupNote;
use App\Integrations\Import\Rows\MediaRow;
use App\Library\PlayDating;
use App\Support\Forms\CredentialField;
use App\Support\Forms\CredentialFieldType;
use Carbon\CarbonImmutable;
use Generator;
use LogicException;

/**
 * A public Steam library, by profile.
 *
 * A game with play time is finished — one
 * completed play carrying the total time (minutes) on the day it was last
 * played; the user changes it to dropped where that is the truth. A game
 * never started comes over only when the user ticks the box, and then onto
 * the watchlist. Steam knows no finishing, so nothing more is guessed
 * (Yamtrack's "played in the last two weeks = in progress" is not).
 *
 * Games are placed by their Steam app id through IGDB's external games —
 * never by name. Steam's API answers only with a key, the user's own; the
 * form carries it for this one import and nothing keeps it.
 */
class SteamSource implements ExplainsSetup, ReadsCredentials
{
    public const CODE = 'steam';

    /** @var array<string, list<array<array-key, mixed>>> profile key => its library, fetched once per run */
    private array $games = [];

    public function __construct(private readonly SteamClient $client) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.steam');
    }

    public function description(): string
    {
        return __('import.games_you_played_on_steam_with');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_steam');
    }

    public function credentialsSchema(): array
    {
        return [
            new CredentialField('profile', __('import.steam_profile'), CredentialFieldType::Text, __('import.your_profile_address_or_your_steamid64')),
            new CredentialField('key', __('import.steam_web_api_key'), CredentialFieldType::Password, __('import.the_key_from_the_steam_page_linked')),
            new CredentialField('unplayed', __('import.put_games_you_never_started_on'), CredentialFieldType::Checkbox),
        ];
    }

    public function notes(): array
    {
        return [new SetupNote(__('import.make_a_steam_web_api_key'), SteamClient::KEY_URL)];
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        $profile = SteamProfile::parse($input->credential('profile'));

        if ($profile === null) {
            return ImportManifest::invalid(__('import.enter_your_steam_profile_address_or'));
        }

        try {
            $games = $this->games($input->credential('key'), $profile);
        } catch (SteamNotFound|SteamRejected|SteamUnavailable $e) {
            return ImportManifest::invalid($e->wording());
        }

        $played = count(array_filter($games, fn (array $game): bool => $this->minutes($game) > 0));
        $unplayed = $input->credential('unplayed') === '1' ? count($games) - $played : 0;

        return ImportManifest::whole([
            new ManifestCategory('played', __('import.played'), $played),
            new ManifestCategory('unplayed', __('import.never_started'), $unplayed),
        ]);
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $withUnplayed = $input->credential('unplayed') === '1';
        // The run follows an analysis that accepted the profile.
        $profile = SteamProfile::parse($input->credential('profile')) ?? throw new LogicException('Rows of a Steam profile the analysis refused.');

        foreach ($this->games($input->credential('key'), $profile) as $game) {
            $appId = $game['appid'] ?? null;

            if (! is_int($appId)) {
                continue;
            }

            $name = is_string($game['name'] ?? null) && $game['name'] !== '' ? $game['name'] : "Steam app {$appId}";
            $subject = MediaSubject::external(MediaKind::Game, [ExternalIdSource::Steam->value => (string) $appId], "{$name} (Steam {$appId})");
            $minutes = $this->minutes($game);

            if ($minutes > 0) {
                // Steam knows the last launch, not a viewing: the play is "has played".
                yield new MediaRow($subject, plays: [new ImportPlay($this->lastPlayed($game), amount: $minutes, dating: PlayDating::Activity)]);
            } elseif ($withUnplayed) {
                yield new MediaRow($subject, watchlist: new ImportMark);
            }
        }
    }

    /**
     * @return list<array<array-key, mixed>>
     *
     * @throws SteamNotFound|SteamRejected|SteamUnavailable
     */
    private function games(string $key, SteamProfile $profile): array
    {
        return $this->games[$profile->key()] ??= $this->client->ownedGames(
            $key,
            $profile->isCustomName ? $this->client->resolveVanityName($key, $profile->value) : $profile->value,
        );
    }

    /**
     * @param  array<array-key, mixed>  $game
     */
    private function minutes(array $game): int
    {
        return is_int($game['playtime_forever'] ?? null) ? $game['playtime_forever'] : 0;
    }

    /**
     * Steam's last launch, a Unix time; 0 or absent when it never recorded
     * one (very old play time) — then the play takes the game's release date.
     *
     * @param  array<array-key, mixed>  $game
     */
    private function lastPlayed(array $game): ?CarbonImmutable
    {
        $at = $game['rtime_last_played'] ?? null;

        return is_int($at) && $at > 0 ? CarbonImmutable::createFromTimestamp($at) : null;
    }
}
