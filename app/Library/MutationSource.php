<?php

namespace App\Library;

use InvalidArgumentException;

/**
 * Where a state mutation came in from: the UI, the REST API, an import job or
 * a scrobble webhook. Carried by every domain event so later consumers
 * (outgoing webhooks, push) can tell a user's click from a bulk import.
 *
 * A class rather than an enum because two of the kinds are parameterized —
 * "import:trakt", "scrobble:jellyfin". There is deliberately no sync kind:
 * VideTrack never synchronizes with another service.
 */
final readonly class MutationSource
{
    public const UI = 'ui';

    public const API = 'api';

    public const IMPORT = 'import';

    public const SCROBBLE = 'scrobble';

    /** Concrete sources name themselves in lowercase — "trakt", "jellyfin". */
    private const CODE_FORMAT = '/^[a-z0-9][a-z0-9_-]*$/';

    private function __construct(
        public string $kind,
        public ?string $code = null,
    ) {}

    public static function ui(): self
    {
        return new self(self::UI);
    }

    public static function api(): self
    {
        return new self(self::API);
    }

    public static function import(string $code): self
    {
        return new self(self::IMPORT, self::validCode($code));
    }

    public static function scrobble(string $code): self
    {
        return new self(self::SCROBBLE, self::validCode($code));
    }

    /**
     * Stable textual form — it lands in logs, job payloads and outgoing
     * webhook bodies, so it is a contract, not a debugging aid.
     */
    public function value(): string
    {
        return $this->code === null ? $this->kind : "{$this->kind}:{$this->code}";
    }

    /**
     * Inverse of value(): rebuilds the source a queued job was handed.
     */
    public static function fromValue(string $value): self
    {
        [$kind, $code] = array_pad(explode(':', $value, 2), 2, null);

        return match (true) {
            $kind === self::UI && $code === null => self::ui(),
            $kind === self::API && $code === null => self::api(),
            $kind === self::IMPORT && $code !== null => self::import($code),
            $kind === self::SCROBBLE && $code !== null => self::scrobble($code),
            default => throw new InvalidArgumentException("Unknown mutation source [{$value}]."),
        };
    }

    /**
     * Kind test, optionally narrowed to one code: is('import') covers every
     * import, is('scrobble', 'jellyfin') only that source.
     */
    public function is(string $kind, ?string $code = null): bool
    {
        return $this->kind === $kind && ($code === null || $this->code === $code);
    }

    private static function validCode(string $code): string
    {
        if (preg_match(self::CODE_FORMAT, $code) !== 1) {
            throw new InvalidArgumentException("Invalid mutation source code [{$code}].");
        }

        return $code;
    }
}
