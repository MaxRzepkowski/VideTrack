<?php

namespace App\Http\Controllers;

use App\Support\FrontTranslations;
use App\Support\Locales;
use Illuminate\Http\Response;

/**
 * One language's texts for the front, under an address that carries their
 * fingerprint: what it names never changes, so a browser downloads it once
 * and keeps it until a text does.
 */
final class TranslationsController
{
    /** A year: the longest lifetime caches honour. */
    private const KEEP_SECONDS = 31_536_000;

    public function __invoke(string $locale, string $version, FrontTranslations $translations): Response
    {
        abort_unless(in_array($locale, Locales::supported(), true), 404);

        // An outdated fingerprint (a tab open across a deploy) still gets
        // today's texts, but nobody may keep them under the old address.
        $current = $translations->version($locale) === $version;

        return response($translations->json($locale), 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => $current ? 'public, max-age='.self::KEEP_SECONDS.', immutable' : 'no-store',
        ]);
    }
}
