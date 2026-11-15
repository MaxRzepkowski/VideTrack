<?php

namespace App\Actions\Media;

use App\Actions\Library\WithdrawCreatorBacking;
use App\Catalog\Creators\CreatorKind;
use App\Models\MediaList;
use Illuminate\Support\Facades\DB;

/**
 * Drops the system list of a collection gone from its provider. The bells it
 * put on its parts go with it — the parts have no reason to stay followed once
 * the saga is gone — unless somebody else backs them or the user rang them by
 * hand. The parts themselves, and anyone's history on them, stay.
 */
final readonly class DeleteCollection
{
    public function __construct(private WithdrawCreatorBacking $withdraw) {}

    public function execute(MediaList $collection): void
    {
        DB::transaction(function () use ($collection): void {
            $this->withdraw->execute(CreatorKind::Collection, $collection->id);
            $collection->delete();
        });
    }
}
