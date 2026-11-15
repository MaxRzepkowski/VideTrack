<?php

namespace App\Suggestions;

use App\Catalog\CardSnapshot;
use App\Catalog\Contracts\SupportsCrewRoles;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Department;
use App\Catalog\MediaKind;
use App\Catalog\ProviderRegistry;
use App\Catalog\Sets\SetStore;
use App\Models\CatalogSetTitle;
use App\Models\LibraryEntry;
use App\Models\User;
use App\Suggestions\Data\Craft;
use App\Suggestions\Data\PersonStanding;
use App\Suggestions\Data\PersonWork;
use App\Support\QueryRow;
use App\Tracking\Creators\PersonTitles;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * What the shelves about people share: who the user keeps coming back to in
 * a craft (from the credits of the films stored here), and what of those
 * people's fetched filmographies the user has not seen. Films only: a show's
 * credits are per episode, and "the director of a show" names nobody.
 */
final readonly class PeopleWork
{
    public function __construct(
        private SetStore $sets,
        private SuggestionFilter $filter,
        private PersonTitles $personTitles,
        private ProviderRegistry $providers,
    ) {}

    /**
     * The job names that single out a department's lead, as the providers
     * of this instance write them.
     *
     * @return list<string>
     */
    public function leadJobs(Department $department): array
    {
        $jobs = [];

        foreach ($this->providers->allSupporting(SupportsCrewRoles::class) as $provider) {
            if ($provider instanceof SupportsCrewRoles) {
                array_push($jobs, ...$provider->leadJobs($department));
            }
        }

        return array_values(array_unique($jobs));
    }

    /**
     * Everyone credited in the craft on a film the user has finished, with
     * how many such films and how the user rated them.
     *
     * @return list<PersonStanding>
     */
    public function standings(User $user, Craft $craft): array
    {
        // One row per person and film first: someone credited twice on a film
        // (Writer and Screenplay) has still made one film.
        $films = DB::table('credits')
            ->join('media_items', 'media_items.id', '=', 'credits.media_item_id')
            ->whereIn('media_items.id', LibraryEntry::query()->finishedTitlesOf($user)->select('media_item_id'))
            ->where('media_items.type', MediaKind::Movie->value)
            ->where('credits.department', $craft->department->value)
            ->when($craft->roles !== null, fn ($query) => $query->whereIn('credits.role', $craft->roles ?? []))
            ->when($craft->maxBilling !== null, fn ($query) => $query->where('credits.order', '<', $craft->maxBilling))
            ->select('credits.person_id', 'media_items.id as media_item_id')
            ->distinct();

        $rows = DB::query()
            ->fromSub($films, 'films')
            ->join('people', 'people.id', '=', 'films.person_id')
            ->leftJoin('ratings', fn (JoinClause $join) => $join
                ->on('ratings.media_item_id', '=', 'films.media_item_id')
                ->where('ratings.user_id', '=', $user->id))
            ->groupBy('people.id', 'people.provider', 'people.provider_ref')
            ->get([
                'people.provider',
                'people.provider_ref',
                DB::raw('count(*) as finished'),
                DB::raw('count(ratings.id) as rated'),
                DB::raw('coalesce(sum(ratings.rating), 0) as rating_sum'),
            ]);

        $standings = [];

        foreach ($rows as $row) {
            $standings[] = new PersonStanding(
                QueryRow::text($row, 'provider'),
                QueryRow::text($row, 'provider_ref'),
                QueryRow::int($row, 'finished'),
                QueryRow::int($row, 'rated'),
                QueryRow::int($row, 'rating_sum'),
            );
        }

        return $standings;
    }

    /** The user's average rating of the films they rated; null before the first. */
    public function ownAverage(User $user): ?float
    {
        $average = DB::table('ratings')
            ->join('media_items', 'media_items.id', '=', 'ratings.media_item_id')
            ->where('ratings.user_id', $user->id)
            ->where('media_items.type', MediaKind::Movie->value)
            ->avg('ratings.rating');

        return is_numeric($average) ? (float) $average : null;
    }

    /**
     * Each person's films in the craft, as their fetched filmography lists
     * them, against what the user has seen. People whose filmography is not
     * fetched yet are left out; so are titles too little known to count.
     *
     * @param  list<PersonStanding>  $people
     * @return list<PersonWork>
     */
    public function work(User $viewer, string $locale, array $people, Craft $craft): array
    {
        $minVotes = config()->integer('tracker.suggestions.min_votes');
        // A filmography holds every credit; only the craft's films that are
        // known widely enough are read.
        $sets = $this->sets->read(
            array_map(fn (PersonStanding $person) => $person->filmography(), $people),
            $locale,
            fn ($credits) => $credits
                ->where('type', MediaKind::Movie->value)
                ->where('department', $craft->department->value)
                ->where('votes', '>=', $minVotes),
        );
        $films = [];

        foreach ($people as $person) {
            $set = $sets[$person->filmography()->id()] ?? null;

            if ($set === null || $set->name === null) {
                continue;
            }

            $own = [];

            foreach ($set->titles as $credit) {
                if ($this->counts($credit, $craft, $minVotes) && ! $this->personTitles->isSelfAppearance($credit->role, [$set->name], $locale)) {
                    $own[$credit->ref] = CardSnapshot::of($credit);
                }
            }

            $films[] = [$person, $set->name, $set->poster_path, array_values($own)];
        }

        $standings = $this->filter->standings($viewer, array_merge([], ...array_column($films, 3)));
        $work = [];

        foreach ($films as [$person, $name, $photo, $own]) {
            $out = array_values(array_filter($own, $standings->isOut(...)));

            $work[] = new PersonWork(
                standing: $person,
                name: $name,
                photoPath: $photo,
                finished: count(array_filter($out, $standings->hasFinished(...))),
                total: count($out),
                missing: array_values(array_filter($out, $standings->isOpen(...))),
            );
        }

        return $work;
    }

    private function counts(CatalogSetTitle $credit, Craft $craft, int $minVotes): bool
    {
        return $credit->type === MediaKind::Movie->value
            && $credit->department === $craft->department
            && ($craft->roles === null || in_array($credit->role, $craft->roles, true))
            && ($craft->maxBilling === null || ($credit->billing !== null && $credit->billing < $craft->maxBilling))
            && ($credit->votes ?? 0) >= $minVotes;
    }

    /**
     * The newest first: what a person made lately is what a reader meets first.
     *
     * @param  list<MediaSummary>  $films
     * @return list<MediaSummary>
     */
    public function newestFirst(array $films): array
    {
        usort($films, fn (MediaSummary $a, MediaSummary $b): int => [$b->releaseDate, $a->title] <=> [$a->releaseDate, $b->title]);

        return $films;
    }
}
