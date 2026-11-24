<?php

/**
 * Narrows the UI to English and Polish, the two languages recorded fixtures
 * and expectations name: a title stores its name in every UI language, so
 * each language the app gains would otherwise rewrite them.
 */
function speakEnglishAndPolish(): void
{
    config(['tracker.supported_locales' => ['en', 'pl']]);
}
