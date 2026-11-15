<?php

namespace App\Http\Controllers\Settings;

use App\Accounts\Data\ApiTokenItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreApiTokenRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Personal access tokens for the REST API. A token is the user's own key to
 * their own instance — there is no OAuth dance here, and nobody else's
 * account is ever involved.
 */
class ApiTokenController extends Controller
{
    public function index(#[CurrentUser] User $viewer): Response
    {
        // The new token rides this page's props once, and the browser keeps
        // props in its history: encrypted there, unreadable once signed out.
        Inertia::encryptHistory();

        return Inertia::render('settings/Api', [
            'tokens' => $viewer->tokens()
                ->latest()
                ->get()
                ->map(ApiTokenItem::of(...))
                ->values()
                ->all(),
            // Shown once, right after creation: the database keeps only its hash.
            'createdToken' => session('created_token'),
            'docsUrl' => config('tracker.api_docs_url'),
        ]);
    }

    public function store(#[CurrentUser] User $viewer, StoreApiTokenRequest $request): RedirectResponse
    {
        $token = $viewer->createToken($request->name(), $request->abilities(), $request->expiresAt());

        return back()->with('created_token', $token->plainTextToken);
    }

    public function destroy(PersonalAccessToken $token): RedirectResponse
    {
        Gate::authorize('delete', $token);

        $token->delete();

        return back();
    }
}
