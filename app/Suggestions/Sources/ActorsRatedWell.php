<?php

namespace App\Suggestions\Sources;

use App\Catalog\Department;
use App\Models\User;
use App\Suggestions\Contracts\SuggestionSource;
use App\Suggestions\Data\Craft;
use App\Suggestions\Data\PersonStanding;
use App\Suggestions\Data\PersonWork;
use App\Suggestions\Data\Suggestion;
use App\Suggestions\Data\SuggestionGroup;
use App\Suggestions\PeopleWork;

/**
 * The unseen films of the actors whose films the user rates above their own
 * average. "Well" is relative to the user, not a fixed mark, and an actor's
 * average is pulled toward the user's own so a couple of hits do not make a
 * favourite. Only films the actor is near the top of the cast of.
 */
final readonly class ActorsRatedWell implements SuggestionSource
{
    public function __construct(private PeopleWork $people) {}

    public function title(): string
    {
        return __('suggestions.actors_rated_well');
    }

    public function description(): string
    {
        return __('suggestions.actors_rated_well_rule');
    }

    public function origins(): array
    {
        return [];
    }

    public function wantedSets(User $user): array
    {
        return array_map(fn (PersonStanding $person) => $person->filmography(), $this->favourites($user));
    }

    public function groups(User $viewer, string $locale): array
    {
        $work = array_values(array_filter(
            $this->people->work($viewer, $locale, $this->favourites($viewer), $this->craft()),
            fn (PersonWork $work): bool => $work->missing !== [],
        ));

        return array_map(function (PersonWork $work): SuggestionGroup {
            $caption = trans_choice('suggestions.your_average_across', $work->standing->rated, [
                'average' => number_format($work->standing->average(), 1),
                'count' => $work->standing->rated,
            ]);

            return new SuggestionGroup(
                suggestions: array_map(fn ($film) => new Suggestion($film, [$work->name, $caption]), $this->people->newestFirst($work->missing)),
                key: $work->key(),
                title: $work->name,
                href: $work->href(),
                caption: $caption,
                posterPath: $work->photoPath,
            );
        }, $work);
    }

    private function craft(): Craft
    {
        return new Craft(Department::Acting, maxBilling: config()->integer('tracker.suggestions.actors.max_billing'));
    }

    /**
     * The actors rated often enough and well enough, the best liked first.
     *
     * @return list<PersonStanding>
     */
    private function favourites(User $user): array
    {
        $own = $this->people->ownAverage($user);

        if ($own === null) {
            return [];
        }

        $minRated = config()->integer('tracker.suggestions.actors.min_rated');
        $prior = config()->integer('tracker.suggestions.actors.prior_films');
        $bar = $own + config()->float('tracker.suggestions.actors.margin');

        $favourites = array_values(array_filter(
            $this->people->standings($user, $this->craft()),
            fn (PersonStanding $person): bool => $person->rated >= $minRated && $person->pulledAverage($own, $prior) >= $bar,
        ));

        usort($favourites, fn (PersonStanding $a, PersonStanding $b): int => [$b->pulledAverage($own, $prior), $a->ref] <=> [$a->pulledAverage($own, $prior), $b->ref]);

        return array_slice($favourites, 0, config()->integer('tracker.suggestions.people_per_shelf'));
    }
}
