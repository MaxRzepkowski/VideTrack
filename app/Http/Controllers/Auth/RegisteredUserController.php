<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Users\RegisterAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Show the registration page.
     */
    public function create(): Response
    {
        return Inertia::render('auth/Register');
    }

    public function store(RegisterRequest $request, RegisterAccount $register): RedirectResponse
    {
        /** @var array{name: string, username: string, email: string, password: string} $account */
        $account = $request->safe()->only(['name', 'username', 'email', 'password']);

        $user = $register->execute(...$account);

        event(new Registered($user));

        Auth::login($user);

        return to_route('dashboard');
    }
}
