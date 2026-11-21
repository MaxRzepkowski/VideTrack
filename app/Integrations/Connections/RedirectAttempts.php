<?php

namespace App\Integrations\Connections;

use App\Integrations\Connections\Contracts\AuthorizesByRedirect;
use App\Integrations\Connections\Data\ConnectionGrant;
use App\Models\User;
use App\Support\Payload;
use App\Support\ServiceUnavailable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * A trip to a service under way, one per user and service: a random state
 * the service carries there and back, and what its sign-in needs once the
 * user returns, encrypted in the cache. Only an answer carrying the state
 * reaches the service, and only once; an answer without it leaves the
 * attempt standing, so a stray link cannot spend it. An attempt the user
 * never returns from lapses.
 */
final readonly class RedirectAttempts
{
    /** Long enough to sign in at the service and approve. */
    private const ATTEMPT_SECONDS = 900;

    private const STATE_LENGTH = 40;

    /**
     * Where to send the user.
     *
     * @throws ConnectionRejected|ServiceUnavailable
     */
    public function begin(User $user, AuthorizesByRedirect $connectable, string $service, string $callbackUrl): string
    {
        $state = Str::random(self::STATE_LENGTH);
        $authorization = $connectable->beginRedirect($callbackUrl, $state);

        Cache::put($this->key($user, $service), Crypt::encryptString(json_encode(['state' => $state, 'kept' => $authorization->kept], JSON_THROW_ON_ERROR)), self::ATTEMPT_SECONDS);

        return $authorization->url;
    }

    /**
     * The grant the trip back stands for.
     *
     * @param  array<string, string>  $answer
     *
     * @throws ConnectionRejected the trip back is no attempt of this user's, or the service refused
     * @throws ServiceUnavailable
     */
    public function complete(User $user, AuthorizesByRedirect $connectable, string $service, array $answer, string $callbackUrl): ConnectionGrant
    {
        $key = $this->key($user, $service);
        $attempt = $this->recall($key);
        $state = $answer['state'] ?? null;

        if ($attempt === null || $state === null || ! hash_equals($attempt['state'], $state)) {
            throw new ConnectionRejected('import.the_connection_attempt_expired');
        }

        // Taken once: a second trip back with the same answer finds nothing.
        if (Cache::pull($key) === null) {
            throw new ConnectionRejected('import.the_connection_attempt_expired');
        }

        return $connectable->completeRedirect($answer, $attempt['kept'], $callbackUrl);
    }

    /**
     * @return array{state: string, kept: array<string, string>}|null
     */
    private function recall(string $key): ?array
    {
        $stored = Cache::get($key);

        if (! is_string($stored)) {
            return null;
        }

        // One written under another APP_KEY is no attempt to go on with.
        try {
            $attempt = Payload::map(json_decode(Crypt::decryptString($stored), true));
        } catch (DecryptException) {
            return null;
        }

        $state = Payload::string($attempt, 'state');

        return $state === null ? null : ['state' => $state, 'kept' => Payload::stringMap($attempt['kept'] ?? null)];
    }

    /** The page error a trip back to $service ends with when it connects nothing. */
    public static function errorKey(string $service): string
    {
        return "connection_{$service}";
    }

    private function key(User $user, string $service): string
    {
        return "connections:redirect:{$user->id}:{$service}";
    }
}
