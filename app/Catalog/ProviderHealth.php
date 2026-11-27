<?php

namespace App\Catalog;

use App\Catalog\Data\ProviderFailure;
use App\Catalog\Data\ProviderStatus;
use App\Support\Payload;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * How each metadata provider has been answering, remembered between requests
 * and runs. Its clients tell it how each request they sent went; it lives in
 * the cache, being fleeting and learnt again within a quarter of an hour.
 */
final class ProviderHealth
{
    /** A provider nobody has heard from for a day has no known state. */
    private const FORGET_AFTER_SECONDS = 86400;

    /**
     * How long one process leaves a state it wrote alone: a refresh sends
     * thousands of requests, and each need not write that the provider answered.
     */
    public const REWRITE_AFTER_SECONDS = 60;

    /** @var array<string, array{state: ProviderState, at: CarbonImmutable}> */
    private array $written = [];

    /**
     * Sends one request to $provider through $ask and remembers how it went:
     * any answer it reads (a "not found", a request it does not take) clears
     * the state, a refused key marks it, an outage or a limit marks it
     * degraded.
     *
     * @template T
     *
     * @param  Closure(): T  $ask
     * @return T
     */
    public function watch(string $provider, Closure $ask): mixed
    {
        try {
            $answer = $ask();
        } catch (ProviderKeyRejected $e) {
            $this->note($provider, ProviderState::KeyRejected, $this->failureOf($e));

            throw $e;
        } catch (ProviderRejected|ItemNotFoundException $e) {
            $this->note($provider, ProviderState::Healthy);

            throw $e;
        } catch (ProviderException $e) {
            $this->note($provider, ProviderState::Degraded, $this->failureOf($e));

            throw $e;
        }

        $this->note($provider, ProviderState::Healthy);

        return $answer;
    }

    public function of(string $provider): ProviderStatus
    {
        $stored = Payload::map(Cache::get(self::key($provider)));
        $state = Payload::enum($stored, 'state', ProviderState::class);
        $failure = Payload::map($stored['failure'] ?? null);
        $failedAt = Payload::instant($failure['at'] ?? null);

        return new ProviderStatus(
            state: $state ?? ProviderState::Healthy,
            since: $state === null ? null : Payload::instant($stored['since'] ?? null),
            answeredAt: Payload::instant($stored['answeredAt'] ?? null),
            probedAt: Payload::instant($stored['probedAt'] ?? null),
            failure: $failedAt === null ? null : new ProviderFailure(Payload::int($failure, 'status'), Payload::string($failure, 'reason'), $failedAt),
        );
    }

    /** The provider was asked only to see whether it answers. */
    public function probed(string $provider): void
    {
        $current = $this->of($provider);

        $this->store($provider, new ProviderStatus($current->state, $current->since, $current->answeredAt, now()->toImmutable(), $current->failure));
    }

    private function failureOf(ProviderException|ProviderRejected $e): ProviderFailure
    {
        $context = $e->context();

        return new ProviderFailure(Payload::int($context, 'status'), Payload::string($context, 'reason'), now()->toImmutable());
    }

    private function note(string $provider, ProviderState $state, ?ProviderFailure $failure = null): void
    {
        $now = now()->toImmutable();
        $written = $this->written[$provider] ?? null;

        if ($written !== null && $written['state'] === $state && $written['at']->diffInSeconds($now) < self::REWRITE_AFTER_SECONDS) {
            return;
        }

        $this->written[$provider] = ['state' => $state, 'at' => $now];
        $current = $this->of($provider);

        // An outage does not hide a refused key: only an answer ends it.
        if ($state === ProviderState::Degraded && $current->state === ProviderState::KeyRejected) {
            return;
        }

        $this->store($provider, new ProviderStatus(
            state: $state,
            since: $current->state === $state ? ($current->since ?? $now) : $now,
            answeredAt: $state === ProviderState::Healthy ? $now : $current->answeredAt,
            probedAt: $current->probedAt,
            failure: $failure ?? $current->failure,
        ));
    }

    private function store(string $provider, ProviderStatus $status): void
    {
        Cache::put(self::key($provider), [
            'state' => $status->state->value,
            'since' => $status->since?->toIso8601String(),
            'answeredAt' => $status->answeredAt?->toIso8601String(),
            'probedAt' => $status->probedAt?->toIso8601String(),
            'failure' => $status->failure === null ? null : [
                'status' => $status->failure->status,
                'reason' => $status->failure->reason,
                'at' => $status->failure->at->toIso8601String(),
            ],
        ], self::FORGET_AFTER_SECONDS);
    }

    private static function key(string $provider): string
    {
        return "provider-health:{$provider}";
    }
}
