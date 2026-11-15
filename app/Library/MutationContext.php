<?php

namespace App\Library;

/**
 * Request-scoped holder of the current MutationSource. Actions never take a
 * source parameter — the entry layer (API middleware, import job, scrobble
 * pipeline) declares where the traffic came from, and the actions read it
 * only when emitting their event. Web traffic sets nothing and gets "ui".
 *
 * Outside a web/API request the source is only ever set through run(), whose
 * try/finally keeps a throwing callback from leaking its source into the next
 * job on a long-lived queue worker.
 */
final class MutationContext
{
    /** @var list<MutationSource> */
    private array $stack = [];

    public function current(): MutationSource
    {
        return $this->stack === []
            ? MutationSource::ui()
            : $this->stack[count($this->stack) - 1];
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function run(MutationSource $source, callable $callback): mixed
    {
        $this->stack[] = $source;

        try {
            return $callback();
        } finally {
            array_pop($this->stack);
        }
    }
}
