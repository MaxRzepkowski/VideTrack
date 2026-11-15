<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Which of a film's release moments a surface shows. A film has up to two —
 * its premiere and the day it stopped being a cinema-only trip — and viewers
 * want different ones: a cinemagoer the first, someone who never goes the
 * second. A second axis to the tracking matrix, like the notification event
 * toggles: the matrix picks WHICH titles a surface carries, this picks WHICH
 * DATE of them.
 */
#[TypeScript]
enum ReleaseDateMode: string
{
    /** Premiere and digital/disc release, as two separate entries. */
    case Both = 'both';

    /**
     * The premiere alone. TMDB's primary release_date is
     * the theatrical date when there was a cinema run and the streaming date
     * when there was not, so a straight-to-streaming film still shows up
     * rather than silently vanishing.
     */
    case Cinema = 'cinema';

    /**
     * The digital / disc release alone. Carries a blind spot the other modes
     * do not: TMDB's home-release dates are crowdsourced, and while titles
     * above ~25 votes get one within some 40 days, roughly a third of niche
     * (mostly non-English) cinema never does — and those films appear nowhere
     * in this mode.
     */
    case Digital = 'digital';

    public function showsPremiere(): bool
    {
        return $this !== self::Digital;
    }

    public function showsDigital(): bool
    {
        return $this !== self::Cinema;
    }
}
