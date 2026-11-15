<?php

namespace App\Catalog;

use Illuminate\Support\Facades\Log;

/**
 * Provider calls of one page that degrade instead of failing it: a call that
 * hits an outage answers its fallback, and the page asks afterwards whether
 * anything went missing, so it can say so. One instance per page build.
 */
final class ProviderCalls
{
    private bool $failed = false;

    /**
     * @template T
     * @template F
     *
     * @param  callable(): T  $call
     * @param  F  $fallback
     * @return T|F
     */
    public function attempt(callable $call, mixed $fallback): mixed
    {
        try {
            return $call();
        } catch (ItemNotFoundException) {
            // Nothing there is an answer, not a failure: the section is just empty.
            return $fallback;
        } catch (ProviderException|ProviderRejected $e) {
            Log::warning('A page section degraded after a provider failure.', ['exception' => $e::class]);

            $this->failed = true;

            return $fallback;
        }
    }

    /** For a section that cannot be honoured without asking at all. */
    public function markFailed(): void
    {
        $this->failed = true;
    }

    public function failed(): bool
    {
        return $this->failed;
    }
}
