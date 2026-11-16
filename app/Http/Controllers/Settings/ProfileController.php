<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Users\DeleteAccount;
use App\Actions\Users\SetAvatar;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ConfirmAccountRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use App\Support\Locales;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/Profile', ['languages' => Locales::choices()]);
    }

    /**
     * Update the user's profile information. The avatar travels with the
     * same form (staged client-side, persisted only on save).
     */
    public function update(#[CurrentUser] User $viewer, ProfileUpdateRequest $request, SetAvatar $avatar): RedirectResponse
    {
        $viewer->fill($request->profile())->save();

        $upload = $request->file('avatar');

        if ($upload instanceof UploadedFile) {
            $avatar->execute($viewer, (string) $upload->getRealPath());
        } elseif ($request->boolean('remove_avatar')) {
            $avatar->execute($viewer, null);
        }

        return to_route('settings.profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(#[CurrentUser] User $viewer, ConfirmAccountRequest $request, DeleteAccount $deleteAccount): RedirectResponse
    {
        Auth::logout();

        $deleteAccount->execute($viewer);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
