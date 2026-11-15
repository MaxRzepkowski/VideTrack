<?php

namespace App\Catalog\Data;

use App\Catalog\CatalogueShelf;
use App\Catalog\CompanyRole;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One title on a company's page: what it worked on, in what role (developer,
 * publisher, studio, network) and on which shelf (games, films, shows…).
 */
#[TypeScript]
final readonly class CompanyCredit implements JsonSerializable
{
    public function __construct(
        public MediaSummary $media,
        public CompanyRole $role,
        public CatalogueShelf $category,
    ) {}

    /**
     * @return array{
     *     media: MediaSummary,
     *     role: value-of<CompanyRole>,
     *     category: value-of<CatalogueShelf>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'media' => $this->media,
            'role' => $this->role->value,
            'category' => $this->category->value,
        ];
    }
}
