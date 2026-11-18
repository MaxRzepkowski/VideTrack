<?php

namespace App\Suggestions\Sources;

use App\Catalog\Department;
use App\Catalog\LeadJobs;
use App\Models\User;
use App\Suggestions\Contracts\SuggestionSource;
use App\Suggestions\Data\Craft;
use App\Suggestions\Data\PersonStanding;
use App\Suggestions\Data\PersonWork;
use App\Suggestions\Data\Suggestion;
use App\Suggestions\Data\SuggestionGroup;
use App\Suggestions\PeopleWork;

/**
 * The films still unseen of the people behind the camera whose work the user
 * has seen most of: at least a few films, and at least a set share of all the
 * person made. One shelf per craft; the craft and its bars are the subclass's.
 */
abstract readonly class MissingFromCrew implements SuggestionSource
{
    public function __construct(
        private PeopleWork $people,
        private LeadJobs $leadJobs,
    ) {}

    /** The department whose lead the shelf is about. */
    abstract protected function department(): Department;

    private function craft(): Craft
    {
        return new Craft($this->department(), $this->leadJobs->in($this->department()));
    }

    /** The `tracker.suggestions` entry holding this shelf's bars. */
    abstract protected function bars(): string;

    public function origins(): array
    {
        return [];
    }

    public function wantedSets(User $user): array
    {
        return array_map(fn (PersonStanding $person) => $person->filmography(), $this->regulars($user));
    }

    public function groups(User $viewer, string $locale): array
    {
        $minShare = config()->float("tracker.suggestions.{$this->bars()}.min_share");
        $minFinished = config()->integer("tracker.suggestions.{$this->bars()}.min_finished");

        // Counted again on the fetched filmography: a film seen that is too
        // little known to count there does not make someone a regular.
        $work = array_values(array_filter(
            $this->people->work($viewer, $locale, $this->regulars($viewer), $this->craft()),
            fn (PersonWork $work): bool => $work->missing !== [] && $work->finished >= $minFinished && $work->share() >= $minShare,
        ));

        // The person the user is furthest through first; among equals, the one seen most of.
        usort($work, fn (PersonWork $a, PersonWork $b): int => [$b->share(), $b->finished] <=> [$a->share(), $a->finished]);

        return array_map(function (PersonWork $work): SuggestionGroup {
            $caption = __('suggestions.watched_of_parts', ['watched' => $work->finished, 'total' => $work->total]);

            return new SuggestionGroup(
                suggestions: array_map(fn ($film) => new Suggestion($film, [$work->name, $caption]), $this->people->newestFirst($work->missing)),
                key: $work->key(),
                title: $work->name,
                href: $work->href(),
                caption: $caption,
                posterPath: $work->photoPath,
                finished: $work->finished,
                total: $work->total,
            );
        }, $work);
    }

    /**
     * The people with enough finished films to be worth fetching, the most seen first.
     *
     * @return list<PersonStanding>
     */
    private function regulars(User $user): array
    {
        $minFinished = config()->integer("tracker.suggestions.{$this->bars()}.min_finished");

        $regulars = array_values(array_filter(
            $this->people->standings($user, $this->craft()),
            fn (PersonStanding $person): bool => $person->finished >= $minFinished,
        ));

        usort($regulars, fn (PersonStanding $a, PersonStanding $b): int => [$b->finished, $a->ref] <=> [$a->finished, $b->ref]);

        return array_slice($regulars, 0, config()->integer('tracker.suggestions.people_per_shelf'));
    }
}
