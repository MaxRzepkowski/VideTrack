<?php

namespace App\Catalog\Creators;

use App\Catalog\Data\CreditData;
use App\Catalog\Data\PersonCredit;
use App\Catalog\Data\PersonDetails;
use App\Models\Person;
use App\Support\Payload;

/**
 * The rows of people. A person gets one through the credits of a persisted
 * title or on their first own interaction (a bell, a list), the way a first play persists a title — each path told apart by what
 * the caller already holds.
 */
final readonly class PersonRecords
{
    public function __construct(private CreatorPages $pages) {}

    /** The row, asking the provider only when there is none yet. */
    public function ensure(string $provider, string $ref, string $locale): Person
    {
        return $this->find($provider, $ref)
            ?? $this->fromDetails($provider, $ref, $this->pages->person(new CreatorRef(CreatorKind::Person, $provider, $ref), $locale));
    }

    /**
     * Re-reads an existing row from its provider. {@see ensure()} trusts a row
     * once it exists — keeping a portrait current is the scheduled sweep's
     * job, not the request's.
     */
    public function refresh(Person $person, string $locale): Person
    {
        return $this->fromDetails(
            $person->provider,
            $person->provider_ref,
            $this->pages->person(new CreatorRef(CreatorKind::Person, $person->provider, $person->provider_ref), $locale),
        );
    }

    /** The full row from a page payload — the one path that fills biography, dates and metadata. */
    public function fromDetails(string $provider, string $ref, PersonDetails $details): Person
    {
        $person = Person::query()->firstOrNew(['provider' => $provider, 'provider_ref' => $ref]);

        $person->fill([
            'name' => $details->name,
            'profile_path' => $details->profilePath ?? $person->profile_path,
            'biography' => CreatorFields::text($details->biography) ?? $person->biography,
            'birthday' => $details->birthday ?? $person->birthday,
            'deathday' => $details->deathday ?? $person->deathday,
            'metadata' => CreatorFields::metadata($person->metadata, PersonMetadata::fromDetails($details)->toStored()),
            'refreshed_at' => now(),
        ]);

        $person->save();

        return $person;
    }

    /**
     * The row read back as a page, from what {@see fromDetails()} stored: the
     * page a provider outage falls back on, with the credits the caller found.
     *
     * @param  list<PersonCredit>  $credits
     */
    public function details(Person $person, array $credits): PersonDetails
    {
        $metadata = PersonMetadata::fromStored($person->metadata);

        return new PersonDetails(
            ref: $person->provider_ref,
            name: $person->name,
            profilePath: $person->profile_path,
            biography: $person->biography,
            knownForDepartment: $metadata->knownForDepartment,
            birthday: $person->birthday?->toDateString(),
            deathday: $person->deathday?->toDateString(),
            placeOfBirth: $metadata->placeOfBirth,
            origin: $metadata->origin,
            alsoKnownAs: $metadata->alsoKnownAs,
            externalLinks: $metadata->externalLinks,
            credits: $credits,
        );
    }

    /**
     * The row from a credit line alone. A show's cast is dozens of people —
     * fetching a page for each would turn one persist into a rate-limited
     * storm, so the credit's own name and portrait are enough.
     */
    public function fromCredit(CreditData $credit): Person
    {
        $person = Person::query()->firstOrNew(['provider' => $credit->provider, 'provider_ref' => $credit->personRef]);

        $person->fill([
            'name' => $credit->name,
            'profile_path' => $credit->profilePath ?? $person->profile_path,
        ]);

        if (! $person->exists || $person->isDirty()) {
            $person->save();
        }

        return $person;
    }

    private function find(string $provider, string $ref): ?Person
    {
        return Person::query()->where('provider', $provider)->where('provider_ref', $ref)->first();
    }
}
