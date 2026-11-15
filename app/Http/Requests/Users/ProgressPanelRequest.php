<?php

namespace App\Http\Requests\Users;

use App\Enums\MediaUserStatus;
use App\Enums\ProgressSort;
use App\Enums\SortDirection;
use App\Users\Data\ProgressFilters;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The address of the "in progress" panel. Forgiving like every panel's: a
 * value that no longer makes sense falls back instead of failing the page.
 */
class ProgressPanelRequest extends FormRequest
{
    /** A huge search term must not bloat the filtering. */
    private const MAX_QUERY = 100;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
        ];
    }

    public function progressFilters(): ProgressFilters
    {
        $status = MediaUserStatus::tryFrom((string) $this->string('status'));
        $type = (string) $this->string('type');
        $q = trim((string) $this->string('q'));

        return new ProgressFilters(
            sort: ProgressSort::tryFrom((string) $this->string('sort')) ?? ProgressSort::Activity,
            dir: SortDirection::tryFrom((string) $this->string('dir')) ?? SortDirection::Asc,
            type: $type === '' ? null : $type,
            // The two shelves are the only statuses this panel shows.
            status: in_array($status, [MediaUserStatus::InProgress, MediaUserStatus::OnHold], true) ? $status : null,
            q: $q === '' ? null : mb_substr($q, 0, self::MAX_QUERY),
        );
    }
}
