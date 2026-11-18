<?php

namespace App\Actions\Users;

use App\Catalog\Department;
use App\Catalog\ItemMetadata;
use App\Catalog\LeadJobs;
use App\Catalog\Matching;
use App\Catalog\MediaTypeRegistry;
use App\Http\Data\StatisticsCreators;
use App\Models\MediaItem;
use App\Users\Statistics\Data\CraftPeople;
use App\Users\Statistics\Data\PersonTally;
use App\Users\Statistics\StatisticsScope;
use App\Users\Statistics\TitleLookups;
use LogicException;

/**
 * Who made the period's titles: the people credited most often in the cast,
 * as the director and as the writer (the lead jobs only, not assistants or a
 * novel's author), and the authors of what was read, each counting titles,
 * once per title.
 */
final readonly class BuildStatisticsCreators
{
    /** People shown per craft. */
    private const SHOWN = 5;

    /** The crafts read from stored credits, in the order the page shows them; authors follow. */
    private const CREDITED = [Department::Acting, Department::Directing, Department::Writing];

    public function __construct(
        private MediaTypeRegistry $types,
        private TitleLookups $lookups,
        private LeadJobs $leadJobs,
    ) {}

    public function execute(StatisticsScope $scope): StatisticsCreators
    {
        $ids = TitleLookups::rootIds($scope->counted);
        $titles = $this->lookups->items($ids, ['type', 'metadata']);
        $tallies = [];
        $types = [];

        foreach (self::CREDITED as $department) {
            // The cast counts whoever is in it; the crew only the job that makes the title someone's own.
            $credited = $this->lookups->credited($ids, $department, $department === Department::Acting ? null : $this->leadJobs->in($department));
            $tallies[$department->value] = $this->most($credited['people']);
            $types[$department->value] = array_map(
                fn (int $id): string => ($titles->get($id) ?? throw new LogicException("Title {$id} was credited but not read."))->type,
                $credited['titles'],
            );
        }

        $people = $this->lookups->people(array_merge(...array_map(fn (array $tally): array => array_keys($tally), array_values($tallies))));
        $crafts = [];

        foreach (self::CREDITED as $department) {
            $shown = [];

            foreach ($tallies[$department->value] as $personId => $count) {
                $person = $people->get($personId) ?? throw new LogicException("Person {$personId} was credited but not read.");
                $shown[] = new PersonTally($person->name, $person->provider, $person->provider_ref, $person->profile_path, $count);
            }

            if ($shown !== []) {
                $crafts[] = new CraftPeople($department, $this->types->ordered(array_unique($types[$department->value])), $shown);
            }
        }

        $authors = $this->authors($titles->all());

        return new StatisticsCreators($authors === null ? $crafts : [...$crafts, $authors]);
    }

    /**
     * The authors of the titles that name theirs: one name per author however
     * the titles spell it (see Matching::sameAuthor), the first spelling met.
     *
     * @param  array<int, MediaItem>  $titles
     */
    private function authors(array $titles): ?CraftPeople
    {
        $names = [];
        $counts = [];
        $types = [];

        foreach ($titles as $title) {
            $credited = [];

            foreach (ItemMetadata::fromStored($title->metadata)->authors as $name) {
                $index = $this->authorIndex($names, $name);

                if ($index === null) {
                    $index = count($names);
                    $names[] = $name;
                    $counts[] = 0;
                }

                $credited[$index] = true;
            }

            foreach (array_keys($credited) as $index) {
                $counts[$index]++;
            }

            if ($credited !== []) {
                $types[$title->type] = true;
            }
        }

        if ($names === []) {
            return null;
        }

        $authors = array_map(fn (string $name, int $count): PersonTally => new PersonTally($name, null, null, null, $count), $names, $counts);

        return new CraftPeople(Department::Author, $this->types->ordered(array_keys($types)), $this->top($authors));
    }

    /** @param  list<string>  $names */
    private function authorIndex(array $names, string $name): ?int
    {
        foreach ($names as $index => $known) {
            if (Matching::sameAuthor($known, $name)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The people credited on the most titles, most first; a tie goes to who
     * was stored first, so the cut does not shift between visits.
     *
     * @param  array<int, int>  $titles  person id => titles
     * @return array<int, int>
     */
    private function most(array $titles): array
    {
        $ids = array_keys($titles);
        usort($ids, fn (int $a, int $b): int => [$titles[$b], $a] <=> [$titles[$a], $b]);

        $most = [];

        foreach (array_slice($ids, 0, self::SHOWN) as $id) {
            $most[$id] = $titles[$id];
        }

        return $most;
    }

    /**
     * The authors with the most titles, most first, by name on a tie.
     *
     * @param  list<PersonTally>  $people
     * @return list<PersonTally>
     */
    private function top(array $people): array
    {
        usort($people, fn (PersonTally $a, PersonTally $b): int => [$b->titles, $a->name] <=> [$a->titles, $b->name]);

        return array_slice($people, 0, self::SHOWN);
    }
}
