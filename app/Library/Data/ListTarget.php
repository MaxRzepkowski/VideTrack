<?php

namespace App\Library\Data;

use App\Catalog\Creators\CreatorKind;
use App\Models\ListItem;
use LogicException;

/**
 * What a list position holds: exactly one title, person or company. The
 * factories are the only way in, so a target never names two or none.
 */
final readonly class ListTarget
{
    private function __construct(
        public ?int $itemId = null,
        public ?int $personId = null,
        public ?int $companyId = null,
    ) {}

    public static function item(int $id): self
    {
        return new self(itemId: $id);
    }

    public static function person(int $id): self
    {
        return new self(personId: $id);
    }

    public static function company(int $id): self
    {
        return new self(companyId: $id);
    }

    public static function creator(CreatorKind $kind, int $id): self
    {
        return match ($kind) {
            CreatorKind::Person => self::person($id),
            CreatorKind::Company, CreatorKind::Network => self::company($id),
            CreatorKind::Collection => throw new LogicException('A collection goes on no list.'),
        };
    }

    public static function ofRow(ListItem $row): self
    {
        return match (true) {
            $row->person_id !== null => self::person($row->person_id),
            $row->company_id !== null => self::company($row->company_id),
            $row->media_item_id !== null => self::item($row->media_item_id),
            default => throw new LogicException("List item [{$row->id}] holds nothing."),
        };
    }

    /**
     * The row's column and value, to find the position or to write it.
     *
     * @return array{media_item_id: int}|array{person_id: int}|array{company_id: int}
     */
    public function attributes(): array
    {
        return match (true) {
            $this->itemId !== null => ['media_item_id' => $this->itemId],
            $this->personId !== null => ['person_id' => $this->personId],
            default => ['company_id' => (int) $this->companyId],
        };
    }
}
