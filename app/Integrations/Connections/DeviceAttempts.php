<?php

namespace App\Integrations\Connections;

use App\Integrations\Connections\Contracts\AuthorizesByDevice;
use App\Integrations\Connections\Data\ConnectionGrant;
use App\Integrations\Connections\Data\DeviceAuthorization;
use App\Models\User;
use App\Support\Payload;
use App\Support\ServiceUnavailable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * A device flow under way, one per user and service: the device code the
 * instance polls with and what the card's fields held when it began
 * (credentials, so they rest encrypted in the cache), and the pace
 * the service asked for. The pace is kept here, not trusted to the page: a
 * poll arriving before the interval is up answers "pending" without asking
 * the service, so a busy tab cannot earn the instance a slow_down lockout.
 *
 * One poll asks the service at a time, and a poll belongs to the attempt it
 * read: when the user started over while the service was answering, what it
 * answered about the old code neither replaces nor ends the new attempt.
 */
final readonly class DeviceAttempts
{
    /** Longer than the slowest answer a poll waits for. */
    private const POLL_LOCK_SECONDS = 90;

    /** RFC 8628 §3.5: slow_down adds five seconds to the interval. */
    private const SLOW_DOWN_SECONDS = 5;

    /**
     * @param  array<string, string>  $values  the connection fields, as typed
     *
     * @throws ConnectionRejected|ServiceUnavailable
     */
    public function begin(User $user, AuthorizesByDevice $connectable, string $service, array $values): DeviceAuthorization
    {
        $authorization = $connectable->beginDeviceAuthorization($values);

        $this->remember($this->key($user, $service), [
            'device_code' => $authorization->deviceCode,
            'values' => $values,
            'interval' => $authorization->interval,
            'next_poll_at' => now()->addSeconds($authorization->interval)->getTimestamp(),
            'expires_at' => now()->addSeconds($authorization->expiresIn)->getTimestamp(),
        ], $authorization->expiresIn);

        return $authorization;
    }

    /**
     * One look at whether the user approved: the grant, or where the attempt
     * stands. A service that cannot be reached now is "not yet".
     *
     * @throws ConnectionRejected the service refused the attempt, which is over
     */
    public function poll(User $user, AuthorizesByDevice $connectable, string $service): ConnectionGrant|ConnectAttemptStatus
    {
        $key = $this->key($user, $service);

        $attempt = $this->recall($key);

        if ($attempt === null || now()->getTimestamp() >= $attempt['expires_at']) {
            Cache::forget($key);

            return ConnectAttemptStatus::Expired;
        }

        if (now()->getTimestamp() < $attempt['next_poll_at']) {
            return ConnectAttemptStatus::Pending;
        }

        $lock = Cache::lock("{$key}:poll", self::POLL_LOCK_SECONDS);

        if (! $lock->get()) {
            return ConnectAttemptStatus::Pending;
        }

        try {
            return $this->ask($connectable, $key, $attempt);
        } finally {
            $lock->release();
        }
    }

    public function cancel(User $user, string $service): void
    {
        Cache::forget($this->key($user, $service));
    }

    /**
     * @param  array{device_code: string, values: array<string, string>, interval: int, next_poll_at: int, expires_at: int}  $attempt
     */
    private function ask(AuthorizesByDevice $connectable, string $key, array $attempt): ConnectionGrant|ConnectAttemptStatus
    {
        try {
            $result = $connectable->pollDeviceAuthorization($attempt['device_code'], $attempt['values']);
        } catch (ServiceUnavailable) {
            return ConnectAttemptStatus::Pending;
        } catch (ConnectionRejected $rejected) {
            $this->forgetIfStill($key, $attempt['device_code']);

            throw $rejected;
        }

        if ($result instanceof ConnectionGrant) {
            $this->forgetIfStill($key, $attempt['device_code']);

            return $result;
        }

        if ($result === DevicePoll::Expired) {
            $this->forgetIfStill($key, $attempt['device_code']);

            return ConnectAttemptStatus::Expired;
        }

        if (! $this->stillAsked($key, $attempt['device_code'])) {
            return ConnectAttemptStatus::Pending;
        }

        // slow_down: the service reset its window on that very poll, so the
        // next attempt waits the grown interval from now.
        $interval = $attempt['interval'] + ($result === DevicePoll::SlowDown ? self::SLOW_DOWN_SECONDS : 0);

        $this->remember($key, [
            ...$attempt,
            'interval' => $interval,
            'next_poll_at' => now()->addSeconds($interval)->getTimestamp(),
        ], max(1, $attempt['expires_at'] - now()->getTimestamp()));

        return ConnectAttemptStatus::Pending;
    }

    /** The attempt in the cache is still the one the poll asked about. */
    private function stillAsked(string $key, string $deviceCode): bool
    {
        return ($this->recall($key)['device_code'] ?? null) === $deviceCode;
    }

    private function forgetIfStill(string $key, string $deviceCode): void
    {
        if ($this->stillAsked($key, $deviceCode)) {
            Cache::forget($key);
        }
    }

    /**
     * The attempt, its device code and typed values encrypted: once
     * approved, the code is exchanged for a grant, so it is a credential and
     * rests like one.
     *
     * @param  array{device_code: string, values: array<string, string>, interval: int, next_poll_at: int, expires_at: int}  $attempt
     */
    private function remember(string $key, array $attempt, int $seconds): void
    {
        Cache::put($key, [
            ...$attempt,
            'device_code' => Crypt::encryptString($attempt['device_code']),
            'values' => Crypt::encrypt($attempt['values']),
        ], $seconds);
    }

    /**
     * @return array{device_code: string, values: array<string, string>, interval: int, next_poll_at: int, expires_at: int}|null
     */
    private function recall(string $key): ?array
    {
        /** @var array{device_code: string, values?: string, interval: int, next_poll_at: int, expires_at: int}|null $stored */
        $stored = Cache::get($key);

        // An attempt kept without what was typed cannot be polled for: it
        // reads as gone, and the user starts again.
        if ($stored === null || ! isset($stored['values'])) {
            return null;
        }

        // One written under another APP_KEY is no attempt to go on with.
        try {
            return [...$stored, 'device_code' => Crypt::decryptString($stored['device_code']), 'values' => Payload::stringMap(Crypt::decrypt($stored['values']))];
        } catch (DecryptException) {
            return null;
        }
    }

    private function key(User $user, string $service): string
    {
        return "connections:device:{$user->id}:{$service}";
    }
}
