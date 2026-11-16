<?php

namespace App\Catalog\Providers\Mal;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What an author did on a manga, as MAL splits the work: the story, the art,
 * or both ("Story & Art", one mangaka doing everything).
 */
#[TypeScript]
enum MangaAuthorRole: string
{
    case Story = 'story';
    case Art = 'art';

    /**
     * The parts one of MAL's role texts names; none for a text MAL has not
     * been seen to send, so its author stays an author without a part.
     *
     * @return list<self>
     */
    public static function fromMal(?string $role): array
    {
        return match ($role) {
            'Story' => [self::Story],
            'Art' => [self::Art],
            'Story & Art' => [self::Story, self::Art],
            default => [],
        };
    }
}
