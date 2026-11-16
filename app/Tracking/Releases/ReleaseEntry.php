<?php

namespace App\Tracking\Releases;

use App\Catalog\Data\MediaSummary;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One release of a tracked title: the card announcing it, and the title it
 * belongs to — an episode belongs to its show, a film or a game to itself.
 * The front calls it a calendar entry: `ReleaseEntry` there is a film's
 * dated release in one country.
 */
#[TypeScript(name: 'CalendarEntry')]
final readonly class ReleaseEntry implements JsonSerializable
{
    public function __construct(
        public MediaSummary $media,
        /** The tracked title (hierarchy root) the release belongs to. */
        public int $rootId,
        public string $rootType,
        /** The show's title, for an episode. */
        public ?string $showTitle = null,
    ) {}

    /** A part of its title (an episode of a show), not the title itself. */
    public function isPart(): bool
    {
        return $this->media->type !== $this->rootType;
    }

    /**
     * The episode opening a numbered season, as the provider marks it: a show
     * whose numbering runs on across seasons opens one past episode 1, and
     * specials premiere nothing.
     */
    public function opensSeason(): bool
    {
        return $this->isPart() && $this->media->episodeBadge?->isPremiere() === true;
    }

    /**
     * @return array{media: MediaSummary, showTitle: string|null}
     */
    public function jsonSerialize(): array
    {
        return ['media' => $this->media, 'showTitle' => $this->showTitle];
    }
}
