<?php

namespace App\Integrations\Nuvio;

use App\Integrations\Connections\ConnectionRejected;
use App\Integrations\Connections\Contracts\AuthorizesByForm;
use App\Integrations\Connections\Contracts\RevokesAccess;
use App\Integrations\Connections\Data\ConnectionChoice;
use App\Integrations\Connections\Data\ConnectionGrant;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Support\Forms\CredentialField;
use App\Support\Forms\CredentialFieldType;

/**
 * Connecting a Nuvio account: its e-mail and password, signed in to a
 * session of this instance's own (the password is never kept), then which
 * of its profiles is this user. Nuvio's cloud unless an address of the
 * user's own Nuvio server is given. Connecting starts at the moment it is
 * made unless the whole history is asked for: the library may hold that
 * history already, from wherever else it was tracked.
 */
class NuvioService implements AuthorizesByForm, RevokesAccess
{
    public const CODE = 'nuvio';

    public function __construct(private readonly NuvioClient $client) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return NuvioClient::NAME;
    }

    public function connectionFields(): array
    {
        return [
            new CredentialField('email', __('common.email_address'), CredentialFieldType::Email),
            new CredentialField('password', __('common.password'), CredentialFieldType::Password),
            new CredentialField('url', __('import.your_own_nuvio_server'), CredentialFieldType::Url, __('import.leave_it_empty_for_nuvio_cloud', ['example' => 'https://nuvio.example.com']), optional: true),
            new CredentialField('history', __('import.also_add_your_history_from_nuvio'), CredentialFieldType::Checkbox, __('import.off_only_what_you_watch_from_now')),
        ];
    }

    public function verify(User $owner, array $values): array
    {
        $server = $this->client->server($values['url'], $owner);
        $session = $this->client->signIn($server, $values['email'], $values['password']);
        try {
            $profiles = $this->profiles($server, $session['access_token']);
        } finally {
            // Only a look: the session that connects is made by connect().
            $this->client->endSession($server, $session['access_token']);
        }

        return array_map(fn (array $profile): ConnectionChoice => new ConnectionChoice((string) $profile['index'], $profile['name']), $profiles);
    }

    public function connect(User $owner, array $values, ?string $choice): ConnectionGrant
    {
        $server = $this->client->server($values['url'], $owner);
        $session = $this->client->signIn($server, $values['email'], $values['password']);

        try {
            return $this->grant($server, $session, $values, $choice);
        } catch (ConnectionRejected $refused) {
            // Not connected after all: the session made for it goes too.
            $this->client->endSession($server, $session['access_token']);

            throw $refused;
        }
    }

    /**
     * @param  array{access_token: string, refresh_token: string, expires_at: int}  $session
     * @param  array<string, string>  $values
     *
     * @throws ConnectionRejected
     */
    private function grant(NuvioServer $server, array $session, array $values, ?string $choice): ConnectionGrant
    {
        foreach ($this->profiles($server, $session['access_token']) as $profile) {
            if ((string) $profile['index'] !== $choice) {
                continue;
            }

            return new ConnectionGrant(
                credentials: [
                    'url' => $server->url,
                    'key' => $server->isCloud() ? null : $server->key,
                    ...$session,
                ],
                accountLabel: "{$values['email']} – {$profile['name']}",
                settings: [
                    'profile' => $profile['index'],
                    // From now on: the feed's position as the account is connected.
                    'start_event_id' => $values['history'] === '1' ? null : $this->client->cursorNow($server, $session['access_token'], $profile['index']),
                ],
            );
        }

        throw new ConnectionRejected('import.pick_which_nuvio_profile_is_yours');
    }

    /**
     * The account's profiles; one without any has nothing to read yet.
     *
     * @return non-empty-list<array{index: int, name: string}>
     *
     * @throws ConnectionRejected
     */
    private function profiles(NuvioServer $server, string $accessToken): array
    {
        $profiles = $this->client->profiles($server, $accessToken);

        return $profiles === [] ? throw new ConnectionRejected('import.this_nuvio_account_has_no_profile_yet') : $profiles;
    }

    public function revoke(ServiceConnection $connection): void
    {
        $this->client->signOut($connection);
    }
}
