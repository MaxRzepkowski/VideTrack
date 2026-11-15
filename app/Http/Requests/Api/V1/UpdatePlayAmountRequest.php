<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Corrects the recorded cost of one play (games: minutes of that
 * playthrough). Null clears it; zero is not a cost, so it is refused rather
 * than quietly read as "clear".
 */
class UpdatePlayAmountRequest extends FormRequest
{
    /** Ownership before the body: a foreign play is 404 whatever was sent. */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('play'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['present', 'nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    public function amount(): ?int
    {
        return $this->filled('amount') ? (int) $this->integer('amount') : null;
    }
}
