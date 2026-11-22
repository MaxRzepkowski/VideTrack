<?php

namespace App\Support;

/**
 * The texts the front speaks, one language at a time, with the fallback
 * language beneath: a text not yet translated reads in it rather than as its
 * key. Built from the language files the server itself reads, so a page has
 * them before it draws its first word, and only the reader's language leaves.
 */
final class FrontTranslations
{
    /** Hex digits of the fingerprint in a dictionary's address. */
    public const VERSION_LENGTH = 16;

    /** @var array<string, string> locale => the dictionary as JSON */
    private array $json = [];

    public function __construct(private readonly LangFiles $files) {}

    /** The dictionary as the front reads it, as JSON. */
    public function json(string $locale): string
    {
        return $this->json[$locale] ??= json_encode(
            [...$this->files->texts(Locales::fallback()), ...$this->files->texts($locale)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * A fingerprint of what the dictionary is made of: the language files and
     * the release that builds them. It moves whenever a text does, so the
     * address carrying it may be kept forever. Read off the files, without
     * building the dictionary, because every page names it.
     */
    public function version(string $locale): string
    {
        $fingerprint = hash('xxh128', config()->string('tracker.version').$this->files->fingerprint(Locales::fallback(), $locale));

        return substr($fingerprint, 0, self::VERSION_LENGTH);
    }
}
