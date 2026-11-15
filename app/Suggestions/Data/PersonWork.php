<?php

namespace App\Suggestions\Data;

use App\Catalog\Data\MediaSummary;

/**
 * One person's work in a craft, as their fetched filmography lists it, set
 * against what the user has seen of it.
 */
final readonly class PersonWork
{
    /**
     * @param  int  $finished  titles of the work the user has finished
     * @param  int  $total  titles of the work that are out
     * @param  list<MediaSummary>  $missing  out, and nothing the user has begun, finished or dropped
     */
    public function __construct(
        public PersonStanding $standing,
        public string $name,
        public ?string $photoPath,
        public int $finished,
        public int $total,
        public array $missing,
    ) {}

    public function share(): float
    {
        return $this->total === 0 ? 0.0 : $this->finished / $this->total;
    }

    /** The person within their shelf, as an address names them. */
    public function key(): string
    {
        return "{$this->standing->provider}-{$this->standing->ref}";
    }

    public function href(): string
    {
        return route('person.details', ['provider' => $this->standing->provider, 'ref' => $this->standing->ref], false);
    }
}
