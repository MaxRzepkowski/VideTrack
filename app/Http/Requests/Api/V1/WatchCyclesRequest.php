<?php

namespace App\Http\Requests\Api\V1;

use App\Catalog\MediaTypeRegistry;
use Illuminate\Validation\Validator;

/**
 * Viewing cycles belong to a root with a hierarchy under it (a show): only
 * there does "start again from the first episode" mean anything.
 */
class WatchCyclesRequest extends MediaRequest
{
    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $definition = app(MediaTypeRegistry::class)->get($this->mediaType());

                if (! $definition->isRoot() || $definition->childType === null) {
                    $validator->errors()->add('type', __('app.this_title_cannot_be_rewatched_from'));
                }
            },
        ];
    }
}
