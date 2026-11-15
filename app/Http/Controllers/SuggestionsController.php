<?php

namespace App\Http\Controllers;

use App\Actions\Suggestions\BuildShelfPage;
use App\Actions\Suggestions\BuildSuggestionsPage;
use App\Http\Requests\Suggestions\ShelfRequest;
use App\Models\User;
use App\Suggestions\SuggestionSources;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Suggestions: titles the user has a countable reason to catch up on, a
 * shelf per rule. Worked out from what is stored here; no provider is asked
 * on the way, and nothing here ever notifies.
 */
class SuggestionsController extends Controller
{
    public function index(#[CurrentUser] User $viewer, BuildSuggestionsPage $build): Response
    {
        return Inertia::render('Suggestions', $build->execute($viewer, App::getLocale()));
    }

    /** A kind of suggestion, or one of its subjects (a collection) when the address names one. */
    public function show(#[CurrentUser] User $viewer, ShelfRequest $request, SuggestionSources $sources, BuildShelfPage $build, string $shelf, ?string $subject = null): Response
    {
        $source = $sources->find($shelf);

        abort_if($source === null, 404);

        $props = $build->execute($viewer, $shelf, $source, $subject, $request->shelfQuery(), App::getLocale());

        abort_if($props === null, 404);

        return Inertia::render('suggestions/Shelf', $props);
    }
}
