<?php

namespace App\Tracking\Creators;

use App\Accounts\Preferences\CompanyFollowScope;
use App\Catalog\CompanyRole;
use App\Catalog\Creators\CompanyRecords;
use App\Catalog\Creators\CreatorKind;
use App\Catalog\Creators\CreatorPages;
use App\Catalog\Creators\CreatorRef;
use App\Catalog\Data\CompanyCredit;
use App\Catalog\MatureChoices;
use App\Models\User;
use App\Notifications\MediaEventType;
use App\Tracking\Data\CreatorTitle;
use App\Tracking\Data\CreatorTitles;

/**
 * A followed studio, publisher or TV network's titles — the same page the
 * company page shows. A title credited in two roles (a game both developed
 * and published) stays twice, so a follower who scopes to one role still
 * finds it.
 */
final readonly class CompanyTitles implements CreatorTitleSource
{
    public function __construct(
        private CreatorPages $pages,
        private CompanyRecords $records,
    ) {}

    public function read(CreatorRef $creator, string $locale): ?CreatorTitles
    {
        if (! $this->pages->serves($creator)) {
            return null;
        }

        // What a followed company announces is the same for every follower.
        $details = $this->pages->company($creator, $locale, MatureChoices::shared());

        // As with people: the sweep gives the row its full set of columns.
        $this->records->fromDetails($creator->provider, $creator->ref, $creator->kind, $details);

        return new CreatorTitles(
            name: $details->name,
            credits: array_map(fn (CompanyCredit $credit): CreatorTitle => new CreatorTitle($credit->media, $credit->role), $details->credits),
            creditedKeys: array_fill_keys(array_map(fn (CompanyCredit $credit): string => "{$credit->media->type}:{$credit->media->ref}", $details->credits), true),
        );
    }

    /**
     * A network airs and a film studio produces — one role each, so every
     * title counts. Developed / published is a game question, so the
     * follower's scope narrows game companies alone.
     */
    public function scopesFor(User $follower, CreatorRef $creator, CreatorTitles $titles): array
    {
        if ($creator->kind === CreatorKind::Network) {
            return [CompanyRole::Network];
        }

        return [
            CompanyRole::Studio,
            ...match ($follower->preferences->companyFollowScope()) {
                CompanyFollowScope::Developed => [CompanyRole::Developer],
                CompanyFollowScope::Published => [CompanyRole::Publisher],
                CompanyFollowScope::Both => [CompanyRole::Developer, CompanyRole::Publisher],
            },
        ];
    }

    public function announcement(): MediaEventType
    {
        return MediaEventType::CompanyNewTitle;
    }
}
