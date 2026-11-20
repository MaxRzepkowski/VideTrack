<?php

namespace App\Actions\Media;

use App\Actions\Library\BackCreatorTitles;
use App\Actions\Library\WithdrawCreatorBacking;
use App\Catalog\CompanyRole;
use App\Catalog\Creators\CreatorKind;
use App\Catalog\Creators\CreatorRef;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Department;
use App\Catalog\TitleStore;
use App\Enums\FollowMode;
use App\Models\Company;
use App\Models\Follow;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\User;
use App\Notifications\Events\CreatorNewTitle;
use App\Support\ExternalServiceException;
use App\Tracking\Creators\CompanyTitles;
use App\Tracking\Creators\CreatorTitleSource;
use App\Tracking\Creators\PersonTitles;
use App\Tracking\Data\CreatorSyncResult;
use App\Tracking\Data\CreatorTitle;
use App\Tracking\Data\CreatorTitles;
use App\Tracking\UpcomingTitleWindow;
use Illuminate\Support\Collection;

/**
 * A followed person, studio, publisher or network as an event source:
 * re-reads its page and backs each follower's share of what it has coming
 * with an auto-follow, announcing a title the first time it lands. From there
 * the ordinary "followed" machinery takes over — calendar, countdowns, the
 * premiere ping. A credit that vanished (a fixed mis-attribution, a cancelled
 * casting) withdraws its backing. Runs daily for every followed creator and
 * once right after one is followed.
 *
 * What differs between a person and a company — which credits are noise,
 * how a follower narrows them, how a new title is announced — is the
 * {@see CreatorTitleSource}'s; the rest is one algorithm.
 */
final readonly class SyncCreatorTitles
{
    /** Upcoming titles backed per follower — a prolific creator stays a signal, not a flood. */
    private const MAX_PER_FOLLOWER = 10;

    /**
     * Titles fetched per scope the followers hold, as a multiple of the cap:
     * room for the few a fetch fails on or finds already out, without a sweep
     * walking a whole filmography while the provider turns every fetch down.
     */
    private const FETCHES_PER_SCOPE = self::MAX_PER_FOLLOWER * 2;

    public function __construct(
        private PersonTitles $people,
        private CompanyTitles $companies,
        private TitleStore $persist,
        private BackCreatorTitles $back,
        private WithdrawCreatorBacking $withdraw,
        private SendMediaNotification $send,
        private UpcomingTitleWindow $window,
    ) {}

    public function execute(Person|Company $creator): CreatorSyncResult
    {
        $kind = $creator instanceof Person ? CreatorKind::Person : CreatorKind::from($creator->kind);
        $ref = new CreatorRef($kind, $creator->provider, $creator->provider_ref);
        $source = $creator instanceof Person ? $this->people : $this->companies;

        /** @var Collection<int, User> $followers */
        $followers = Follow::query()
            ->where(Follow::creatorColumn($kind), $creator->id)
            ->where('mode', FollowMode::Follow->value)
            ->with('user')
            ->get()
            ->map(fn (Follow $follow): User => $follow->user)
            ->keyBy('id');

        if ($followers->isEmpty()) {
            return new CreatorSyncResult;
        }

        $locale = config()->string('tracker.default_locale');

        try {
            $titles = $source->read($ref, $locale);
        } catch (ExternalServiceException $e) {
            return new CreatorSyncResult(errors: ["{$kind->value}:{$creator->provider_ref} ({$creator->name}): {$e->wording()}"]);
        }

        if ($titles === null) {
            return new CreatorSyncResult;
        }

        // Every upcoming credit, uncapped: the cap applies where rows are
        // backed, and the sweep must see every valid credit, or a title pushed
        // past the cap would read as a withdrawn one.
        $upcoming = collect($titles->credits)
            ->filter(fn (CreatorTitle $title): bool => $this->window->admits($title->media))
            ->sort(fn (CreatorTitle $a, CreatorTitle $b): int => $this->window->compare($a->media, $b->media))
            ->values();

        $scopes = $followers->map(fn (User $follower): array => $source->scopesFor($follower, $ref, $titles));

        $backed = $this->backUpcoming($creator, $kind, $source, $titles, $upcoming, $followers, $scopes, $locale);
        $removed = $this->withdrawVanished($creator, $kind, $titles, $upcoming, $followers, $scopes);

        return new CreatorSyncResult($backed->created, $removed, $backed->errors);
    }

    /**
     * Each follower's first titles within their scope, persisted lazily — a
     * credit nobody wants never touches the provider — and backed in one
     * write per follower. A title only its home release kept in the window
     * is dropped once persisting shows that release is behind us. Answers
     * what it backed and the titles the provider could not serve; nothing is
     * withdrawn here.
     *
     * @param  Collection<int, CreatorTitle>  $upcoming
     * @param  Collection<int, User>  $followers
     * @param  Collection<int, list<Department|CompanyRole>>  $scopes
     */
    private function backUpcoming(
        Person|Company $creator,
        CreatorKind $kind,
        CreatorTitleSource $source,
        CreatorTitles $titles,
        Collection $upcoming,
        Collection $followers,
        Collection $scopes,
        string $locale,
    ): CreatorSyncResult {
        /** @var array<int, array<string, int>> $picked follower id => title key => item id */
        $picked = [];
        /** @var array<string, MediaItem|null> $items title key => persisted item, null when unusable */
        $items = [];
        $errors = [];
        $fetchBudget = self::FETCHES_PER_SCOPE * $scopes
            ->unique(fn (array $scope): string => implode(',', array_map(fn (Department|CompanyRole $credit): string => $credit->value, $scope)))
            ->count();

        foreach ($upcoming as $title) {
            $key = $title->key();
            $wanting = $followers->filter(fn (User $follower): bool => in_array($title->scope, $scopes->get($follower->id, []), true)
                && ! isset($picked[$follower->id][$key])
                && count($picked[$follower->id] ?? []) < self::MAX_PER_FOLLOWER);

            if ($wanting->isEmpty()) {
                continue;
            }

            if (! array_key_exists($key, $items)) {
                // Spent: a title fetched already may still go to another follower.
                if (count($items) >= $fetchBudget) {
                    continue;
                }

                try {
                    $items[$key] = $this->persistUpcoming($title, $locale);
                } catch (ExternalServiceException $e) {
                    $items[$key] = null;
                    $errors[] = "{$key} ({$title->media->title}): {$e->wording()}";
                }
            }

            $item = $items[$key];

            if ($item === null) {
                continue;
            }

            foreach ($wanting as $follower) {
                $picked[$follower->id][$key] = $item->id;
            }
        }

        $created = 0;
        $announcement = $source->announcement();

        foreach ($followers as $follower) {
            $backed = $this->back->execute($follower, $kind, $creator->id, array_values($picked[$follower->id] ?? []));
            $created += count($backed);

            if ($backed === []) {
                continue;
            }

            // In the order the titles come, soonest first.
            foreach (MediaItem::query()->whereIn('id', $backed)->get()->sortBy(fn (MediaItem $item): int|false => array_search($item->id, $backed, true)) as $item) {
                $event = CreatorNewTitle::of($announcement, $item, $titles->name);

                if ($event->wantedBy($follower)) {
                    $this->send->execute($follower, $event);
                }
            }
        }

        return new CreatorSyncResult(created: $created, errors: $errors);
    }

    /**
     * The title's row, or null once its release turns out to be behind us.
     *
     * @throws ExternalServiceException
     */
    private function persistUpcoming(CreatorTitle $title, string $locale): ?MediaItem
    {
        $item = $this->persist->persist($title->media->type, $title->media->ref, $locale);

        return $this->window->isSpent($title->media, $item) ? null : $item;
    }

    /**
     * The reverse sweep: an upcoming title this creator backs that is no
     * longer among its credits loses the backing — and its row, once nobody
     * else backs it. Released titles keep their rows as history. A title
     * with no date (rows are never created without one) keeps its row while
     * the credit stands — a premiere moved to TBA — and loses it once the
     * credit is gone too, or it would point at a page that 404s forever.
     *
     * @param  Collection<int, CreatorTitle>  $upcoming
     * @param  Collection<int, User>  $followers
     * @param  Collection<int, list<Department|CompanyRole>>  $scopes
     */
    private function withdrawVanished(
        Person|Company $creator,
        CreatorKind $kind,
        CreatorTitles $titles,
        Collection $upcoming,
        Collection $followers,
        Collection $scopes,
    ): int {
        [$pivot, $column] = Follow::sponsorPivot($kind);
        $today = today()->toDateString();

        $backed = Follow::query()
            ->whereIn('user_id', $followers->keys())
            ->whereNotNull('media_item_id')
            ->whereHas(Follow::sponsorRelation($kind), fn ($query) => $query->where("{$pivot}.{$column}", $creator->id))
            ->with('mediaItem')
            ->get()
            ->groupBy('user_id');

        $removed = 0;

        foreach ($followers as $follower) {
            $followed = $backed->get($follower->id);

            if ($followed === null) {
                continue;
            }

            $valid = $upcoming
                ->filter(fn (CreatorTitle $title): bool => in_array($title->scope, $scopes->get($follower->id, []), true))
                ->mapWithKeys(fn (CreatorTitle $title): array => [$title->key() => true]);

            $stale = $followed
                ->filter(function (Follow $follow) use ($valid, $titles, $today): bool {
                    $item = $follow->mediaItem;

                    if ($item === null) {
                        return false;
                    }

                    $key = MediaSummary::keyOf($item->type, $item->provider_ref);

                    return match (true) {
                        $valid->has($key) => false,
                        $item->release_date !== null && $item->release_date->toDateString() <= $today => false,
                        $item->release_date === null => ! isset($titles->creditedKeys[$key]),
                        default => true,
                    };
                })
                ->map(fn (Follow $follow): int => (int) $follow->media_item_id);

            if ($stale->isNotEmpty()) {
                $removed += $this->withdraw->execute($kind, $creator->id, $follower, array_values($stale->all()));
            }
        }

        return $removed;
    }
}
