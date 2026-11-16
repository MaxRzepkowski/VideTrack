<?php

namespace App\Integrations\Generic;

use App\Catalog\ExternalIdSource;
use App\Catalog\MediaKind;
use App\Catalog\MediaTypeRegistry;
use App\Integrations\Scrobble\Concerns\ReadsPlaybackPayloads;
use App\Integrations\Scrobble\Contracts\ScrobbleSource;
use App\Integrations\Scrobble\Data\ScrobbleEvent;
use App\Integrations\Scrobble\Data\SetupStep;
use App\Integrations\Scrobble\ScrobbleEventKind;
use App\Library\PlaybackCompletion;
use App\Library\RatingScale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * Our own format, for whatever has no driver of its own: a shell script, a
 * Home Assistant automation, a listener next to Kodi.
 *
 * Unlike the media servers, the sender here is somebody writing code against
 * us, so the payload is a public contract and it is read strictly: a field
 * we cannot use is answered with a 422 and the reason, not swallowed. Unknown
 * fields are ignored and changes are additive only — a script written today
 * keeps working.
 *
 * Only identifiers place a title. A name and a year are for the log.
 */
class GenericScrobbleSource implements ScrobbleSource
{
    use ReadsPlaybackPayloads;

    public const CODE = 'generic';

    /** The one version of the format there is. */
    public const VERSION = 1;

    /**
     * Which catalogues can name which kind. For an episode they are the
     * show's ids: scripts almost never know an episode's own, and the season
     * and number carry the rest of the way down.
     *
     * Only what actually resolves is offered — steam would be accepted and
     * then never match, which is worse than refusing it — and of these only
     * the kinds whose module this instance runs ({@see kinds()}).
     */
    public const IDS = [
        'movie' => ['tmdb', ExternalIdSource::Imdb->value],
        'episode' => ['tmdb', ExternalIdSource::Imdb->value, ExternalIdSource::Tvdb->value],
        'game' => ['igdb'],
        'book' => ['hardcover', ExternalIdSource::Isbn->value],
        'manga' => ['mal'],
        'comic' => ['comicvine'],
    ];

    /** What a script may report; "added" is a library event no script has. */
    private const EVENTS = [
        ScrobbleEventKind::Complete,
        ScrobbleEventKind::Progress,
        ScrobbleEventKind::Stop,
        ScrobbleEventKind::Start,
        ScrobbleEventKind::Pause,
        ScrobbleEventKind::Rate,
    ];

    public function __construct(private MediaTypeRegistry $types) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return 'Webhook';
    }

    public function description(): string
    {
        return __('integrations.what_your_own_scripts_report_is');
    }

    public function parse(Request $request): ScrobbleEvent
    {
        $payload = $this->readPayload($request);

        $validator = Validator::make($payload, $this->rules($payload), [
            'version.in' => 'Only version '.self::VERSION.' of this format exists.',
            'occurred_at.before_or_equal' => 'The occurred_at field cannot be more than a day ahead.',
        ]);

        $validator->after(fn (ValidatorInstance $validator) => $this->checkIds($validator, $payload));

        /** @var array{event: string, media: array{kind: string, ids: array<string, string|int>, season?: int|null, episode?: int|null, title?: string|null, year?: int|null}, progress?: float|int|null, amount?: int|null, rating?: int|null, occurred_at?: string|null, user?: string|null} $valid */
        $valid = $validator->validate();

        $media = $valid['media'];
        $kind = ScrobbleEventKind::from($valid['event']);
        $ids = array_map(fn (string|int $id): string => trim((string) $id), $media['ids']);
        $episode = $media['kind'] === 'episode';
        $title = $this->text($media['title'] ?? null);
        $season = $media['season'] ?? null;
        $number = $media['episode'] ?? null;

        return new ScrobbleEvent(
            kind: $kind,
            mediaKind: MediaKind::from($media['kind']),
            externalIds: $episode ? [] : $ids,
            seriesExternalIds: $episode ? $ids : [],
            title: $episode ? $this->episodeLabel($title, $season, $number) : $title,
            year: $media['year'] ?? null,
            seasonNumber: $season,
            episodeNumber: $number,
            progressPercent: isset($valid['progress']) ? (float) $valid['progress'] : null,
            position: $valid['amount'] ?? null,
            rating: $kind === ScrobbleEventKind::Rate ? $valid['rating'] ?? null : null,
            occurredAt: $this->moment($valid['occurred_at'] ?? null),
            serverUsername: $this->text($valid['user'] ?? null),
        );
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<string, mixed>
     */
    private function rules(array $payload): array
    {
        $event = $payload['event'] ?? null;

        return [
            'version' => ['sometimes', 'integer', Rule::in([self::VERSION])],
            'event' => ['required', 'string', Rule::in($this->eventNames())],
            'media' => ['required', 'array'],
            'media.kind' => ['required', 'string', Rule::in(array_keys($this->kinds()))],
            'media.ids' => ['required', 'array', 'min:1'],
            // Strings or numbers alike: `"tmdb": 603` is what people type.
            'media.ids.*' => ['required', 'regex:/^\S{1,64}$/'],
            'media.season' => ['required_if:media.kind,episode', 'nullable', 'integer', 'min:0'],
            'media.episode' => ['required_if:media.kind,episode', 'nullable', 'integer', 'min:0'],
            'media.title' => ['nullable', 'string', 'max:255'],
            'media.year' => ['nullable', 'integer', 'between:1800,2200'],
            'progress' => ['nullable', 'numeric', 'between:0,100'],
            // A progress report has to say how far; either measure will do.
            'amount' => [
                Rule::requiredIf($event === 'progress' && ($payload['progress'] ?? null) === null),
                'nullable',
                'integer',
                'min:0',
            ],
            'rating' => ['required_if:event,rate', 'nullable', 'integer', 'between:'.RatingScale::MIN.','.RatingScale::MAX],
            // A clock a little off is normal; a viewing next week is a bug.
            'occurred_at' => ['nullable', 'date', 'before_or_equal:'.now()->addDay()->toIso8601String()],
            'user' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * An isbn on a film is somebody's mistake, and answering it with "no
     * match" would hide which one.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function checkIds(ValidatorInstance $validator, array $payload): void
    {
        $kind = data_get($payload, 'media.kind');
        $ids = data_get($payload, 'media.ids');

        if (! is_string($kind) || ! isset($this->kinds()[$kind]) || ! is_array($ids)) {
            return;
        }

        $foreign = array_diff(array_map('strval', array_keys($ids)), $this->kinds()[$kind]);

        if ($foreign !== []) {
            $validator->errors()->add(
                'media.ids',
                sprintf('A %s is named by %s, not by %s.', $kind, implode(', ', $this->kinds()[$kind]), implode(', ', $foreign)),
            );
        }
    }

    public function instructions(): array
    {
        return [
            new SetupStep(
                __('integrations.post_to_this_address'),
                __('integrations.send_json_with_a_post_to'),
                <<<'SH'
                curl -X POST "$URL" \
                  -H 'Content-Type: application/json' \
                  -d '{"event": "complete", "media": {"kind": "movie", "ids": {"tmdb": 603}}}'
                SH,
            ),
            new SetupStep(
                __('integrations.an_episode'),
                __('integrations.the_ids_name_the_show_season'),
                <<<'SH'
                curl -X POST "$URL" \
                  -H 'Content-Type: application/json' \
                  -d '{"event": "complete", "media": {"kind": "episode", "ids": {"tmdb": 1396}, "season": 1, "episode": 3}}'
                SH,
            ),
            new SetupStep(
                __('common.progress'),
                __('integrations.the_progress_event_takes_amount_pages'),
                <<<'SH'
                curl -X POST "$URL" \
                  -H 'Content-Type: application/json' \
                  -d '{"event": "progress", "media": {"kind": "book", "ids": {"isbn": "9780316769488"}}, "amount": 120}'
                SH,
            ),
            new SetupStep(
                __('integrations.a_rating'),
                __('integrations.the_rate_event_takes_rating_from'),
                <<<'SH'
                curl -X POST "$URL" \
                  -H 'Content-Type: application/json' \
                  -d '{"event": "rate", "media": {"kind": "movie", "ids": {"imdb": "tt0133093"}}, "rating": 9}'
                SH,
            ),
            new SetupStep(
                __('integrations.which_ids_name_what'),
                __('integrations.any_one_of_these_per_title'),
                $this->idTable(),
            ),
            new SetupStep(
                __('integrations.every_field'),
                __('integrations.event_is_complete_progress_stop_start', [
                    'events' => implode(', ', $this->eventNames()),
                    'threshold' => PlaybackCompletion::threshold(),
                ]),
                <<<'JSON'
                {
                  "version": 1,
                  "event": "stop",
                  "media": {
                    "kind": "episode",
                    "ids": { "tmdb": 1396 },
                    "season": 1,
                    "episode": 3,
                    "title": "Breaking Bad",
                    "year": 2008
                  },
                  "progress": 92.5,
                  "amount": null,
                  "rating": null,
                  "occurred_at": "2026-09-26T20:15:00Z",
                  "user": "alex"
                }
                JSON,
            ),
        ];
    }

    public function notice(): ?string
    {
        return null;
    }

    /**
     * The kinds a script may report here: a type whose module is off would
     * be accepted and then never match.
     *
     * @return array<string, list<string>>
     */
    private function kinds(): array
    {
        return array_filter(self::IDS, fn (string $kind): bool => $this->types->has($kind), ARRAY_FILTER_USE_KEY);
    }

    /** @return list<string> */
    private function eventNames(): array
    {
        return array_map(fn (ScrobbleEventKind $kind): string => $kind->value, self::EVENTS);
    }

    /** The ids each kind is named by, from the very table the rules check — they can never disagree. */
    private function idTable(): string
    {
        $lines = [];

        foreach ($this->kinds() as $kind => $ids) {
            $line = str_pad($kind, 10).implode(', ', $ids);
            $lines[] = $kind === 'episode' ? $line.' '.__('integrations.ids_of_the_show') : $line;
        }

        return implode("\n", $lines);
    }
}
