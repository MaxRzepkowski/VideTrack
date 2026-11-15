<?php

namespace App\Http\Requests\Api\V1;

use App\Catalog\MediaTypeRegistry;
use App\Enums\FollowMode;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The bell on a title. It lives on hierarchy roots alone: season and episode
 * events roll up to the show, so subscribing lower means nothing.
 */
class UpdateFollowRequest extends MediaRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            // null clears the exception — the tracking matrix decides again.
            'mode' => ['present', 'nullable', 'string', Rule::enum(FollowMode::class)],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! app(MediaTypeRegistry::class)->get($this->mediaType())->isRoot()) {
                    $validator->errors()->add('type', __('media.only_a_whole_title_can_be_followed'));
                }
            },
        ];
    }

    public function followMode(): ?FollowMode
    {
        return $this->enum('mode', FollowMode::class);
    }
}
