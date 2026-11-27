<?php

namespace App\Actions\Media;

use App\Library\LibraryHolders;
use App\Models\MediaItem;
use App\Support\Numbers;
use Illuminate\Support\Facades\DB;

/**
 * Deletes the titles nothing points at: nobody holds anything in their
 * trees, no list lists them and no bell is on them. Asked again of the whole
 * trees in the same transaction that deletes them, so a title somebody took
 * up since the sweep looked stays. The rows below go with each title, as when
 * a title gone from its provider is deleted ({@see RetireGoneTitle}).
 */
final readonly class RemoveOrphanTitles
{
    public function __construct(private LibraryHolders $holders) {}

    /**
     * @param  list<int>  $rootIds
     * @return int how many went
     */
    public function execute(array $rootIds): int
    {
        return DB::transaction(fn (): int => Numbers::int(MediaItem::query()->whereIn('id', $this->holders->unreferencedOf($rootIds))->delete()) ?? 0);
    }
}
