<?php

namespace App\Http\Resources\Api\V1;

use App\Library\Data\PlayEntry;
use App\Models\Play;
use Illuminate\Http\Request;

/**
 * One closed attempt, as {@see PlayEntry} words it.
 *
 * @mixin Play
 */
class PlayResource extends ApiResource
{
    public function __construct(private readonly Play $play)
    {
        parent::__construct($play);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return PlayEntry::of($this->play)->jsonSerialize();
    }
}
