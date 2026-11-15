<?php

namespace App\Users\Data;

use App\Catalog\Data\MediaSummary;
use App\Tracking\BellEntryKind;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One bell on the following panel: a followed person, company or collection,
 * a title belled by hand or by a followed creator, or a muted one.
 */
#[TypeScript]
final readonly class FollowEntry
{
    public function __construct(
        public MediaSummary $media,
        public BellEntryKind $kind,
        /** The followed creators who rang it, for a title one of them belled. */
        public ?string $personName,
    ) {}
}
