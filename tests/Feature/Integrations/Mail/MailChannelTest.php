<?php

use App\Actions\Media\SendMediaNotification;
use App\Integrations\Mail\MailChannel;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Notifications\Channels\ChannelMode;
use App\Notifications\Channels\ChannelRegistry;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mime\Email;

/*
 * E-mail channels through the instance's own mail settings: offered only
 * where the instance really sends mail, the user naming nothing but an
 * address.
 */

/** The instance sends mail; what it sends stays in memory for the test to read. */
function enableInstanceMail(): void
{
    config(['mail.default' => 'smtp']);
    app(ChannelRegistry::class);
    config(['mail.default' => 'array']);
}

/** @return list<Email> */
function sentMail(): array
{
    $transport = Mail::mailer()->getSymfonyTransport();

    return $transport instanceof ArrayTransport ? array_map(fn ($sent): Email => $sent->getOriginalMessage(), iterator_to_array($transport->messages())) : [];
}

test('an instance that only writes mail to its log offers no e-mail channel', function () {
    expect(app(ChannelRegistry::class)->has(MailChannel::CODE))->toBeFalse();
});

test('an e-mail channel asks for an address alone', function () {
    enableInstanceMail();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('settings.channels.index'))->assertInertia(fn (Assert $page) => $page
        ->where('page.kinds.1.code', MailChannel::CODE)
        ->where('page.kinds.1.fields.0.key', MailChannel::ADDRESS));

    $this->post(route('settings.channels.store'), ['driver' => MailChannel::CODE, 'kind' => MailChannel::CODE, 'mode' => 'instant', 'config' => [MailChannel::ADDRESS => 'not an address']])
        ->assertSessionHasErrors('config.'.MailChannel::ADDRESS);
    $this->post(route('settings.channels.store'), ['driver' => MailChannel::CODE, 'kind' => MailChannel::CODE, 'mode' => 'instant', 'config' => [MailChannel::ADDRESS => 'alex@example.com']])
        ->assertSessionHasNoErrors();

    expect($user->notificationChannels()->sole()->config)->toBe([MailChannel::ADDRESS => 'alex@example.com']);
});

test('a notification is a mail: the headline as its subject, the facts and the link as its text', function () {
    enableInstanceMail();
    $user = User::factory()->create();
    NotificationChannel::factory()->for($user)->create([
        'driver' => MailChannel::CODE,
        'kind' => MailChannel::CODE,
        'mode' => ChannelMode::Instant,
        'config' => [MailChannel::ADDRESS => 'alex@example.com'],
    ]);

    app(SendMediaNotification::class)->execute($user, premiereEvent());

    [$mail] = sentMail();

    expect($mail->getTo()[0]->getAddress())->toBe('alex@example.com')
        ->and($mail->getSubject())->toBe('Out today: Dune')
        ->and(trim((string) $mail->getTextBody()))->toBe(route('movie.details', ['ref' => 'tmdb:1']));
});
