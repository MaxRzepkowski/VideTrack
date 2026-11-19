<?php

namespace App\Http\Requests\Users;

use App\Catalog\MediaTypeRegistry;
use App\Enums\ActivityGrouping;
use App\Enums\ActivitySort;
use App\Enums\PlayOutcome;
use App\Enums\SortDirection;
use App\Users\ActivitySource;
use App\Users\Data\ActivityFilters;
use App\Users\PanelReader;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * The address of an activity panel (history, ratings, favorites). Everything
 * in it is forgiving: back, refresh and shared links keep their place, and a
 * value that no longer makes sense falls back to the panel's default instead
 * of failing the page.
 */
class ActivityPanelRequest extends FormRequest
{
    /** A huge search term must not bloat the query. */
    private const MAX_QUERY = 100;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string'],
            'genre' => ['nullable', 'string'],
            'page' => ['nullable'],
        ];
    }

    /** The panel's filters, falling back to the defaults of what it lists and who reads it. */
    public function activityFilters(ActivitySource $source, PanelReader $reader): ActivityFilters
    {
        $sort = ActivitySort::tryFrom((string) $this->string('sort'));
        $sort = $sort !== null && $source->offers($sort, $reader) ? $sort : $source->defaultSort($reader);
        $type = (string) $this->string('type');
        $q = trim((string) $this->string('q'));
        $genre = trim((string) $this->string('genre'));

        return new ActivityFilters(
            sort: $sort,
            dir: SortDirection::tryFrom((string) $this->string('dir')) ?? $sort->naturalDirection(),
            group: ActivityGrouping::tryFrom((string) $this->string('group')) ?? $source->defaultGrouping(),
            q: $q === '' ? null : mb_substr($q, 0, self::MAX_QUERY),
            genre: $genre === '' ? null : $genre,
            type: app(MediaTypeRegistry::class)->has($type) ? $type : null,
            status: $source->hasOutcome() ? PlayOutcome::tryFrom((string) $this->string('status')) : null,
            from: $this->day('from'),
            to: $this->day('to'),
            hideRated: $this->boolean('hide_rated'),
            hideListed: $this->boolean('hide_listed'),
        );
    }

    public function page(): int
    {
        return max(1, $this->integer('page', 1));
    }

    private function day(string $key): ?string
    {
        $value = (string) $this->string($key);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)?->toDateString();
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
