<?php

namespace App\Http\Resources\Api\V1;

use App\Library\Data\ListAddition;
use Illuminate\Http\Request;

/**
 * One title asked onto a list: as asked, and what became of it. No card: the
 * client named the title by its ref, and the states of those on the list
 * come beside the list of these.
 *
 * @mixin ListAddition
 */
class ListAdditionResource extends ApiResource
{
    public function __construct(private readonly ListAddition $addition)
    {
        parent::__construct($addition);
    }

    /**
     * @return array{type: string, ref: string, outcome: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->addition->type,
            'ref' => $this->addition->ref,
            'outcome' => $this->addition->outcome->value,
        ];
    }
}
