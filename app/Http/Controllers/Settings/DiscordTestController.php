<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Integrations\Discord\DiscordAnswer;
use App\Integrations\Discord\DiscordClient;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "Send a test message" for the Discord webhook: posts to the address typed
 * into the form, so the user checks a webhook before saving it — or, with
 * nothing typed, to the one already saved.
 */
class DiscordTestController extends Controller
{
    public function __invoke(#[CurrentUser] User $viewer, Request $request, DiscordClient $discord): RedirectResponse
    {
        $request->validate([
            'webhook_url' => [
                'nullable', 'string', 'max:400', 'url',
                'starts_with:https://discord.com/api/webhooks/,https://discordapp.com/api/webhooks/',
            ],
        ]);

        $typed = $request->filled('webhook_url') ? $request->string('webhook_url')->value() : null;
        $url = $typed ?? $viewer->discordWebhookUrl()
            ?? throw ValidationException::withMessages(['webhook_url' => __('settings.paste_a_webhook_address_first')]);

        $answer = $discord->send($url, [
            'content' => __('settings.test_notification_your_webhook_works'),
        ]);

        if ($answer !== DiscordAnswer::Delivered) {
            throw ValidationException::withMessages([
                'webhook_url' => __('settings.discord_did_not_accept_the_message'),
            ]);
        }

        return back();
    }
}
