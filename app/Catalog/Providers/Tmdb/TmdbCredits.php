<?php

namespace App\Catalog\Providers\Tmdb;

use App\Catalog\Creators\CreatorKind;
use App\Catalog\Data\CreatorLink;
use App\Catalog\Data\CreditData;
use App\Catalog\Department;
use App\Support\Payload;

/**
 * Cast and crew of a title, a season's aggregate credits and an episode's own:
 * one place that turns TMDB's credit lists into CreditData.
 */
final readonly class TmdbCredits
{
    /**
     * The jobs that make a film someone's own: TMDB files assistants and
     * script supervisors under Directing too, and the author of an adapted
     * novel under Writing.
     *
     * @return list<string>
     */
    public static function leadJobs(Department $department): array
    {
        return match ($department) {
            Department::Directing => ['Director'],
            Department::Writing => ['Writer', 'Screenplay'],
            default => [],
        };
    }

    /** Cast and guests kept per title: the strip and its modal read no further. */
    private const CAST_KEPT = 30;

    public function __construct(
        private TmdbPayload $payload,
    ) {}

    /**
     * Episode-specific credits: the guest stars and the per-episode crew (the
     * regular cast is not here — it comes from the show, see episodeCast).
     *
     * @param  array<array-key, mixed>  $data
     * @return array{0: list<CreditData>, 1: list<CreditData>}
     */
    public function mapEpisodeCredits(array $data): array
    {
        $guests = [];

        $rawGuests = Payload::rows($data['guest_stars'] ?? null);

        foreach (array_slice($rawGuests, 0, self::CAST_KEPT) as $entry) {
            $guests[] = $this->credit(
                $entry,
                department: Department::GuestStar,
                role: Payload::string($entry, 'character'),
                order: Payload::int($entry, 'order') ?? 0,
            );
        }

        $crew = [];

        $rawCrew = Payload::rows($data['crew'] ?? null);

        foreach ($rawCrew as $entry) {
            $crew[] = $this->credit(
                $entry,
                department: $this->payload->department($entry),
                role: Payload::string($entry, 'job'),
            );
        }

        return [$guests, $crew];
    }

    /**
     * The episode's full cast: the show's regular cast first, then the episode
     * guest stars not already among them (deduped by person).
     *
     * @param  list<CreditData>  $regulars
     * @param  list<CreditData>  $guests
     * @return list<CreditData>
     */
    public function episodeCast(array $regulars, array $guests): array
    {
        $seen = [];

        foreach ($regulars as $credit) {
            $seen[$credit->personRef] = true;
        }

        $cast = $regulars;

        foreach ($guests as $guest) {
            if (! isset($seen[$guest->personRef])) {
                $cast[] = $guest;
            }
        }

        return $cast;
    }

    /**
     * A season's aggregate credits (cast across its episodes): each person's
     * primary role/job, ordered as TMDB ranks them.
     *
     * @param  array<array-key, mixed>  $data
     * @return array{0: list<CreditData>, 1: list<CreditData>}
     */
    public function mapAggregateCredits(array $data): array
    {
        $cast = [];

        $rawCast = Payload::rows(data_get($data, 'aggregate_credits.cast'));

        foreach (array_slice($rawCast, 0, self::CAST_KEPT) as $entry) {
            $roles = $this->creditRoles($entry, 'roles', 'character');

            $cast[] = $this->credit(
                $entry,
                department: Department::Acting,
                role: $roles[0]['role'] ?? null,
                order: Payload::int($entry, 'order') ?? 0,
                episodeCount: Payload::positiveInt($entry, 'total_episode_count'),
                roles: $roles,
            );
        }

        $crew = [];

        $rawCrew = Payload::rows(data_get($data, 'aggregate_credits.crew'));

        foreach ($rawCrew as $entry) {
            $roles = $this->creditRoles($entry, 'jobs', 'job');

            $crew[] = $this->credit(
                $entry,
                department: $this->payload->department($entry),
                role: $roles[0]['role'] ?? null,
                episodeCount: Payload::positiveInt($entry, 'total_episode_count'),
                roles: $roles,
            );
        }

        return [$cast, $crew];
    }

    /**
     * The per-character / per-job breakdown of an aggregate-credit entry:
     * each role's name + its episode count, most episodes first.
     *
     * @param  array<array-key, mixed>  $entry
     * @param  string  $listKey  'roles' (cast) or 'jobs' (crew)
     * @param  string  $nameKey  'character' (cast) or 'job' (crew)
     * @return list<array{role: string|null, episodeCount: int|null}>
     */
    private function creditRoles(array $entry, string $listKey, string $nameKey): array
    {
        $roles = [];

        foreach (Payload::list(data_get($entry, $listKey)) as $role) {
            $roles[] = [
                'role' => Payload::string($role, $nameKey),
                'episodeCount' => Payload::positiveInt($role, 'episode_count'),
            ];
        }

        usort($roles, fn (array $a, array $b): int => ($b['episodeCount'] ?? 0) <=> ($a['episodeCount'] ?? 0));

        return $roles;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array{0: list<CreditData>, 1: list<CreditData>}
     */
    public function mapCredits(array $data): array
    {
        $cast = [];
        $crew = [];

        $rawCast = Payload::rows(data_get($data, 'credits.cast'));

        // No cap: the modal advertises "See all cast & crew", and person pages
        // cross-link credits (a cameo billed at #38 must still be findable).
        foreach ($rawCast as $entry) {
            $cast[] = $this->credit(
                $entry,
                department: Department::Acting,
                role: Payload::string($entry, 'character'),
                order: Payload::int($entry, 'order') ?? 0,
            );
        }

        $rawCrew = Payload::rows(data_get($data, 'credits.crew'));

        // TMDB's raw crew order is not importance-ordered — on crew-heavy
        // productions a director can sit past position 200. Pull Directing +
        // Writing rows to the front so those tabs lead the crew modal and the
        // director tops the key-crew block. No cap: the full crew ships.
        $isKey = fn (array $entry): bool => in_array($entry['department'] ?? null, ['Directing', 'Writing'], true);
        $rawCrew = array_merge(
            array_values(array_filter($rawCrew, $isKey)),
            array_values(array_filter($rawCrew, fn (array $entry): bool => ! $isKey($entry))),
        );

        // TMDB lists one row per person-job, so someone credited for several
        // jobs in a department (comic sources: "Characters" + "Comic Book")
        // appears multiple times and the rows interleave between people. Merge
        // per (person, department), keeping each person's first-seen position
        // and sorting their jobs so everyone shares the same job order.
        /** @var array<string, array{entry: array<array-key, mixed>, department: Department, jobs: list<string>}> $groups */
        $groups = [];
        $order = [];

        foreach ($rawCrew as $entry) {
            $department = $this->payload->department($entry);
            $key = $this->payload->ref($entry).'|'.$department->value;
            $job = Payload::string($entry, 'job');

            if (! isset($groups[$key])) {
                $groups[$key] = ['entry' => $entry, 'department' => $department, 'jobs' => []];
                $order[] = $key;
            }

            if ($job !== null && ! in_array($job, $groups[$key]['jobs'], true)) {
                $groups[$key]['jobs'][] = $job;
            }
        }

        foreach ($order as $key) {
            $group = $groups[$key];
            sort($group['jobs']);
            $entry = $group['entry'];

            $crew[] = $this->credit(
                $entry,
                department: $group['department'],
                role: $group['jobs'][0] ?? null,
                // Only expose the per-job breakdown when there is more than one;
                // single-job crew renders through `role`.
                roles: count($group['jobs']) > 1
                    ? array_map(fn (string $job): array => ['role' => $job, 'episodeCount' => null], $group['jobs'])
                    : [],
            );
        }

        return [$cast, $crew];
    }

    /**
     * One person's credit: who they are comes from the entry the same way on
     * every list TMDB sends (cast, crew, guest stars, aggregates); what they
     * did is the caller's.
     *
     * @param  array<array-key, mixed>  $entry
     * @param  list<array{role: string|null, episodeCount: int|null}>  $roles
     */
    private function credit(array $entry, Department $department, ?string $role, int $order = 0, ?int $episodeCount = null, array $roles = []): CreditData
    {
        return new CreditData(
            personRef: $this->payload->ref($entry),
            provider: $this->payload->code(),
            name: Payload::text($entry, 'name'),
            profilePath: Payload::string($entry, 'profile_path'),
            department: $department,
            role: $role,
            order: $order,
            episodeCount: $episodeCount,
            roles: $roles,
        );
    }

    /**
     * People as the creator links every block shows — e.g. a show's
     * created_by. Entries without a name/id are dropped.
     *
     * @return list<CreatorLink>
     */
    public function people(mixed $entries): array
    {
        $people = [];

        foreach (Payload::rows($entries) as $entry) {
            $name = Payload::string($entry, 'name');

            if ($name !== null) {
                $people[] = new CreatorLink($name, CreatorKind::Person, $this->payload->ref($entry), $this->payload->code());
            }
        }

        return $people;
    }
}
