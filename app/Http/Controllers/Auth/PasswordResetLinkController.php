<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /** Mailers that keep the message on the server instead of sending it. */
    private const UNSENT_MAILERS = ['log', 'array'];

    /**
     * Show the password reset link request page. Without a mailer that sends,
     * the page says the link stays on the server, instead of promising mail.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
            'sendsMail' => ! in_array(config('mail.default'), self::UNSENT_MAILERS, true),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        Password::sendResetLink(
            $request->only('email')
        );

        return back()->with('status', __('account.a_reset_link_will_be_sent'));
    }
}
