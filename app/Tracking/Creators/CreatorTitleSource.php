<?php

namespace App\Tracking\Creators;

use App\Actions\Media\SyncCreatorTitles;
use App\Catalog\CompanyRole;
use App\Catalog\Creators\CreatorRef;
use App\Catalog\Department;
use App\Models\User;
use App\Notifications\MediaEventType;
use App\Support\ExternalServiceException;
use App\Tracking\Data\CreatorTitles;

/**
 * What one kind of followed creator has coming, and what of it counts for a
 * given follower — the part of {@see SyncCreatorTitles}
 * that differs between a person and a company.
 */
interface CreatorTitleSource
{
    /**
     * Reads the creator's page at its provider, storing what it says on the
     * creator's row. Null when the provider no longer serves the creator.
     *
     * @throws ExternalServiceException
     */
    public function read(CreatorRef $creator, string $locale): ?CreatorTitles;

    /**
     * The credit scopes that count for this follower.
     *
     * @return list<Department|CompanyRole>
     */
    public function scopesFor(User $follower, CreatorRef $creator, CreatorTitles $titles): array;

    /**
     * How a new title is announced — the event, and with it the switch that
     * silences it.
     */
    public function announcement(): MediaEventType;
}
