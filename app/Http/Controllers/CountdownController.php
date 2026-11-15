<?php

namespace App\Http\Controllers;

use App\Actions\Media\BuildCountdowns;
use App\Http\Requests\CountdownsRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Countdowns: a grid of live countdown cards to every upcoming premiere the
 * user tracks — next episodes, seasons, movies, game releases — across all
 * media types, searchable, filterable by type, sortable and paginated.
 */
class CountdownController extends Controller
{
    public function index(#[CurrentUser] User $viewer, CountdownsRequest $request, BuildCountdowns $countdowns): Response
    {
        return Inertia::render('Countdowns', $countdowns->execute($viewer, $request->countdownFilters(), $request->page()));
    }
}
