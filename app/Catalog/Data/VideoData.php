<?php

namespace App\Catalog\Data;

use App\Catalog\VideoKind;
use App\Catalog\VideoSite;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class VideoData implements JsonSerializable
{
    public function __construct(
        public string $key,
        public VideoSite $site,
        public VideoKind $type,
        public string $name,
        public bool $official = false,
        public ?string $publishedAt = null,
    ) {}

    /**
     * @return array{
     *     key: string,
     *     site: value-of<VideoSite>,
     *     type: value-of<VideoKind>,
     *     name: string,
     *     official: bool,
     *     publishedAt: string|null
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'site' => $this->site->value,
            'type' => $this->type->value,
            'name' => $this->name,
            'official' => $this->official,
            'publishedAt' => $this->publishedAt,
        ];
    }
}
