<?php

namespace App\Catalog\Data;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One language a game speaks, and how: dubbed, subtitled, its menus. The
 * locale is a BCP 47 tag ("pl-PL", "zh-TW"), so the page names it in the
 * reader's own language.
 */
#[TypeScript]
final readonly class LanguageSupport implements JsonSerializable
{
    public function __construct(
        public string $locale,
        public bool $audio = false,
        public bool $subtitles = false,
        public bool $interface = false,
    ) {}

    /**
     * @return array{
     *     locale: string,
     *     audio: bool,
     *     subtitles: bool,
     *     interface: bool
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'locale' => $this->locale,
            'audio' => $this->audio,
            'subtitles' => $this->subtitles,
            'interface' => $this->interface,
        ];
    }
}
