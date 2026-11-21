<?php

namespace App\Integrations\RetroAchievements;

use App\Catalog\MediaKind;
use App\Enums\MediaUserStatus;
use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Contracts\ReadsCredentials;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportListEntry;
use App\Integrations\Import\Data\ImportListRef;
use App\Integrations\Import\Data\ImportManifest;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ImportStatus;
use App\Integrations\Import\Data\ManifestCategory;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Data\SetupNote;
use App\Integrations\Import\Data\TitleQuery;
use App\Integrations\Import\LibraryList;
use App\Integrations\Import\Rows\ListRow;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Support\Forms\CredentialField;
use App\Support\Forms\CredentialFieldType;
use App\Support\Payload;
use App\Support\ServiceBusy;
use App\Support\ServiceUnavailable;
use Generator;

/**
 * The games a RetroAchievements player has played, read with their own web
 * API key (a one-off form: the key is used for the run, not kept).
 *
 * A game with an award (beaten, completed, mastered) was finished on the
 * day of that award; one with achievements but no award is being played.
 * On request every game also goes onto a list named after the service.
 *
 * RetroAchievements knows games by its own ids only, so a game is placed
 * by its title, read the way a catalogue writes it; hacks, homebrew games
 * and subsets of achievements are no catalogue's and stay out with a word.
 */
class RetroAchievementsSource implements ExplainsSetup, ReadsCredentials
{
    public const CODE = 'retroachievements';

    private const FINISHED = 'finished';

    private const PLAYING = 'playing';

    public const SERVICE = 'RetroAchievements';

    /** @var array<string, list<array<array-key, mixed>>> username => their games, fetched once per run */
    private array $games = [];

    public function __construct(private readonly RetroAchievementsClient $client) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return self::SERVICE;
    }

    public function description(): string
    {
        return __('import.games_you_beat_and_play_on_retroachievements');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_retroachievements');
    }

    public function credentialsSchema(): array
    {
        return [
            new CredentialField('username', __('import.retroachievements_username'), CredentialFieldType::Text),
            new CredentialField('key', __('import.web_api_key'), CredentialFieldType::Password, __('import.retroachievements_settings_keys')),
        ];
    }

    public function notes(): array
    {
        return [new SetupNote(__('import.retroachievements_places_games_by_title'))];
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        $username = trim($input->credential('username'));

        if ($username === '' || trim($input->credential('key')) === '') {
            return ImportManifest::invalid(__('import.enter_your_retroachievements_username_and_key'));
        }

        try {
            $games = $this->games($input);
        } catch (RetroAchievementsRejected|ServiceUnavailable $e) {
            return ImportManifest::invalid($e->wording());
        }

        if ($games === []) {
            return ImportManifest::invalid(__('import.retroachievements_shows_no_games_for'));
        }

        // Only what can land is counted: hacks, homebrew and subsets never do.
        $placeable = array_values(array_filter($games, fn (array $game): bool => $this->searchable($game) !== null));
        $finished = count(array_filter($placeable, fn (array $game): bool => $this->award($game) !== null));
        $playing = count(array_filter($placeable, fn (array $game): bool => $this->award($game) === null && $this->earned($game)));

        return ImportManifest::selectable([
            new ManifestCategory(self::FINISHED, __('import.beaten_completed_or_mastered'), $finished),
            new ManifestCategory(self::PLAYING, __('import.games_playing'), $playing),
            (new LibraryList(self::SERVICE))->category(count($placeable)),
        ]);
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $library = new LibraryList(self::SERVICE);
        $list = $library->isPicked($selection) ? $library->ref() : null;

        if ($list !== null) {
            yield new ListRow($list);
        }

        foreach ($this->games($input) as $game) {
            $row = $this->game($game, $selection, $list);

            if ($row !== null) {
                yield $row;
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $game
     */
    private function game(array $game, ImportSelection $selection, ?ImportListRef $list): MediaRow|SkipRow|null
    {
        $award = $this->award($game);
        $finished = $award !== null && $selection->has(self::FINISHED);
        $playing = $award === null && $this->earned($game) && $selection->has(self::PLAYING);

        if (! $finished && ! $playing && $list === null) {
            return null;
        }

        $raw = Payload::string($game, 'Title');

        if ($raw === null) {
            return new SkipRow('import.skip_retroachievements_no_title');
        }

        $console = Payload::string($game, 'ConsoleName');
        $label = $console === null ? $raw : "{$raw} ({$console})";
        $title = RetroAchievementsTitle::searchable($raw);

        if ($title === null) {
            return new SkipRow('import.skip_retroachievements_not_in_a_catalogue', ['label' => $label]);
        }

        return new MediaRow(
            MediaSubject::external(MediaKind::Game, [], $label, byTitle: new TitleQuery($title, exactTitle: true)),
            plays: $finished ? [new ImportPlay(Payload::instant($game['HighestAwardDate'] ?? null))] : [],
            status: $playing ? new ImportStatus(MediaUserStatus::InProgress, Payload::instant($game['MostRecentAwardedDate'] ?? null)) : null,
            lists: $list === null ? [] : [new ImportListEntry($list)],
        );
    }

    /**
     * The name a game is searched by; null for one no catalogue holds.
     *
     * @param  array<array-key, mixed>  $game
     */
    private function searchable(array $game): ?string
    {
        $title = Payload::string($game, 'Title');

        return $title === null ? null : RetroAchievementsTitle::searchable($title);
    }

    /**
     * @param  array<array-key, mixed>  $game
     */
    private function award(array $game): ?RetroAchievementsAward
    {
        return Payload::enum($game, 'HighestAwardKind', RetroAchievementsAward::class);
    }

    /**
     * Whether the player earned any achievement in the game, in either mode.
     *
     * @param  array<array-key, mixed>  $game
     */
    private function earned(array $game): bool
    {
        return (Payload::int($game, 'NumAwarded') ?? 0) > 0 || (Payload::int($game, 'NumAwardedHardcore') ?? 0) > 0;
    }

    /**
     * @return list<array<array-key, mixed>>
     *
     * @throws RetroAchievementsRejected|RetroAchievementsUnavailable|ServiceBusy
     */
    private function games(ImportInput $input): array
    {
        $username = trim($input->credential('username'));

        return $this->games[mb_strtolower($username)] ??= $this->client->completionProgress($username, trim($input->credential('key')));
    }
}
