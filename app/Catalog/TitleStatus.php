<?php

namespace App\Catalog;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Where a title stands in its life: announced, in the making, out, running,
 * over. Providers map their own vocabularies onto it (TMDB's "Returning
 * Series", IGDB's numeric statuses, MAL's "currently_publishing"), so the
 * core and the pages read one set of codes and translate them in one place.
 *
 * The values are the snake_case of the English names — the codes the pages
 * and notifications carry.
 */
#[TypeScript]
enum TitleStatus: string
{
    case Rumored = 'rumored';
    case Planned = 'planned';
    case InProduction = 'in_production';
    case PostProduction = 'post_production';
    case Pilot = 'pilot';
    case Alpha = 'alpha';
    case Beta = 'beta';
    case EarlyAccess = 'early_access';
    case NotYetPublished = 'not_yet_published';
    case Released = 'released';
    case ReturningSeries = 'returning_series';
    case Publishing = 'publishing';
    case OnHiatus = 'on_hiatus';
    case Ended = 'ended';
    case Finished = 'finished';
    case Canceled = 'canceled';
    case Discontinued = 'discontinued';
    case Offline = 'offline';
    case Delisted = 'delisted';

    /** Nothing more is coming: a series that ended or was cut short. */
    public function hasEnded(): bool
    {
        return in_array($this, [self::Ended, self::Finished, self::Canceled, self::Discontinued], true);
    }

    /**
     * Nothing more is coming under this status: out, over or withdrawn. A
     * title in one only changes when a provider corrects it.
     */
    public function isFinal(): bool
    {
        return $this === self::Released || $this === self::Offline || $this === self::Delisted || $this->hasEnded();
    }

    /** @return list<self> */
    public static function finals(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => $status->isFinal()));
    }

    public function label(?string $locale = null): string
    {
        return match ($this) {
            self::Rumored => __('status.rumored', [], $locale),
            self::Planned => __('status.planned', [], $locale),
            self::InProduction => __('status.in_production', [], $locale),
            self::PostProduction => __('status.post_production', [], $locale),
            self::Pilot => __('status.pilot', [], $locale),
            self::Alpha => __('status.alpha', [], $locale),
            self::Beta => __('status.beta', [], $locale),
            self::EarlyAccess => __('status.early_access', [], $locale),
            self::NotYetPublished => __('status.not_yet_published', [], $locale),
            self::Released => __('status.released', [], $locale),
            self::ReturningSeries => __('status.returning_series', [], $locale),
            self::Publishing => __('status.publishing', [], $locale),
            self::OnHiatus => __('status.on_hiatus', [], $locale),
            self::Ended => __('status.ended', [], $locale),
            self::Finished => __('status.finished', [], $locale),
            self::Canceled => __('status.canceled', [], $locale),
            self::Discontinued => __('status.discontinued', [], $locale),
            self::Offline => __('status.offline', [], $locale),
            self::Delisted => __('status.delisted', [], $locale),
        };
    }
}
