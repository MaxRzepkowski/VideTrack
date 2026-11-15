<?php

namespace App\Support;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use LogicException;

/**
 * Codes mapped to the classes behind them — metadata providers, import and
 * scrobble sources, connectable services. Classes resolve lazily from the
 * container, so a test can register a fake and every get() hands out what
 * the container binds — a fresh instance whose memos live for one run, or
 * the one a module scoped to the request or job — as long as it is the
 * class registered under the code. Modules register from their own service
 * providers, behind whatever they need; registration order is the order the
 * pages show.
 *
 * @template T of object
 */
abstract class ServiceRegistry
{
    /** @var array<string, class-string<T>> */
    private array $classes = [];

    public function __construct(private readonly Container $container) {}

    /** What the registry holds, for the error naming an unknown code. */
    abstract protected function kind(): string;

    /**
     * @param  class-string<T>  $class
     */
    public function register(string $code, string $class): void
    {
        $this->classes[$code] = $class;
    }

    public function has(string $code): bool
    {
        return isset($this->classes[$code]);
    }

    /**
     * @return T
     */
    public function get(string $code): object
    {
        if (! isset($this->classes[$code])) {
            throw new InvalidArgumentException("Unknown {$this->kind()} [{$code}].");
        }

        $class = $this->classes[$code];
        $service = $this->container->make($class);

        // A binding may hand out anything under the class's name; what leaves
        // here still has to be what the registry promises.
        return $service instanceof $class ? $service : throw new LogicException("The container resolved [{$class}] to another class.");
    }

    /**
     * The implementation behind a code that came from outside (a URL slug), or
     * null when this instance has none — an unknown code is a 404, not an error.
     *
     * @return T|null
     */
    public function find(string $code): ?object
    {
        return $this->has($code) ? $this->get($code) : null;
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys($this->classes);
    }

    /**
     * The codes whose class has a capability, told from the class alone —
     * nothing is built to ask.
     *
     * @param  class-string  $capability
     * @return list<string>
     */
    public function codesImplementing(string $capability): array
    {
        return array_keys(array_filter($this->classes, fn (string $class): bool => is_a($class, $capability, true)));
    }

    /**
     * @return list<T>
     */
    public function all(): array
    {
        return array_map(fn (string $code): object => $this->get($code), $this->codes());
    }
}
