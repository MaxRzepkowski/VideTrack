<?php

namespace App\Integrations\Steam;

/**
 * The Steam profile a user typed in, read before anything is asked of
 * Steam: a SteamID64, a /profiles/<id> address, a /id/<name> address or the
 * bare custom name. $value is the id, or the custom name Steam resolves to
 * one.
 */
final readonly class SteamProfile
{
    private function __construct(
        public string $value,
        public bool $isCustomName,
    ) {}

    /** Null when what was typed cannot be a Steam profile. */
    public static function parse(string $typed): ?self
    {
        $typed = trim($typed);

        if (preg_match('#^(?:https?://)?(?:www\.)?steamcommunity\.com/profiles/(\d{17})#i', $typed, $match) === 1) {
            return new self($match[1], false);
        }

        if (preg_match('/^\d{17}$/', $typed) === 1) {
            return new self($typed, false);
        }

        $name = preg_match('#steamcommunity\.com/id/([^/?\#]+)#i', $typed, $match) === 1 ? $match[1] : $typed;

        return preg_match('/^[A-Za-z0-9_-]{2,32}$/', $name) === 1 ? new self($name, true) : null;
    }

    /** One spelling per profile, for memos keyed by it. */
    public function key(): string
    {
        return $this->isCustomName ? "id/{$this->value}" : $this->value;
    }
}
