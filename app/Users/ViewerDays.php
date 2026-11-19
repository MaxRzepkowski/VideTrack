<?php

namespace App\Users;

use App\Accounts\UserCalendar;
use App\Support\StoredTime;
use App\Users\Data\ActivityFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The date range of a user view, held to the viewer's days: "1 May" starts
 * at midnight in their zone, whatever zone the entries are stored in.
 */
final readonly class ViewerDays
{
    /**
     * @param  Builder<covariant Model>  $query
     */
    public function apply(Builder $query, string $column, ActivityFilters $filters, UserCalendar $calendar): void
    {
        if ($filters->from !== null) {
            $query->where($column, '>=', StoredTime::of($calendar->startOf($filters->from)));
        }

        if ($filters->to !== null) {
            $query->where($column, '<=', StoredTime::of($calendar->endOf($filters->to)));
        }
    }
}
