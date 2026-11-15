<?php

namespace App\Http\Controllers;

use App\Accounts\UserCalendar;
use App\Models\User;
use App\Tracking\Data\CalendarMonth;
use App\Tracking\Surfaces\CalendarDays;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dedicated release calendar over the same sources as the
 * dashboard widget: one month at a time — every day of it, empty ones included
 * — laid out in reading order rather than under weekday columns. The month
 * ships whole, so the page needs no appending endpoint; prev / next / today /
 * a date jump are each a fresh visit.
 */
class CalendarController extends Controller
{
    public function index(#[CurrentUser] User $viewer, Request $request, CalendarDays $calendar): Response
    {
        $today = UserCalendar::of($viewer)->today();
        $month = $this->day($request->string('date')->toString(), $today)->startOfMonth();

        $feedUrl = route('calendar.feed', ['token' => $viewer->calendarToken()]);

        return Inertia::render('Calendar', [
            'anchor' => $month->toDateString(),
            'today' => $today->toDateString(),
            'prev' => $month->copy()->subMonthNoOverflow()->toDateString(),
            'next' => $month->copy()->addMonthNoOverflow()->toDateString(),
            'month' => $this->month($viewer, $month, $calendar),
            'feedUrl' => $feedUrl,
            'webcalUrl' => (string) preg_replace('#^https?://#', 'webcal://', $feedUrl),
        ]);
    }

    /**
     * The month itself — the 1st to the last day, nothing around it. The view
     * lays those days out in reading order rather than under weekday columns,
     * so the days of the neighbouring months that a seven-wide grid has to draw
     * to fill its corners would only be noise here: they belong to their own
     * month's page.
     */
    private function month(User $user, CarbonImmutable $monthStart, CalendarDays $calendar): CalendarMonth
    {
        $monthEnd = $monthStart->copy()->endOfMonth();

        return new CalendarMonth(
            month: $monthStart->toDateString(),
            start: $monthStart->toDateString(),
            end: $monthEnd->toDateString(),
            days: $calendar->range($user, $monthStart->toDateString(), $monthEnd->toDateString()),
        );
    }

    /** The requested day, or the viewer's today when it is missing or unparseable. */
    private function day(string $date, CarbonImmutable $today): CarbonImmutable
    {
        try {
            return $date === '' ? $today : CarbonImmutable::parse($date)->startOfDay();
        } catch (InvalidFormatException) {
            return $today;
        }
    }
}
