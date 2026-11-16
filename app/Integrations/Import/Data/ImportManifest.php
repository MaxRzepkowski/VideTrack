<?php

namespace App\Integrations\Import\Data;

use Spatie\TypeScriptTransformer\Attributes\Hidden;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What an input holds, read before anything is written. It feeds the
 * selection on the import page and sizes the progress bar of the run.
 */
#[TypeScript]
final readonly class ImportManifest
{
    /**
     * @param  list<ManifestCategory>  $categories
     * @param  list<ManifestList>  $lists
     * @param  int  $unreadable  rows a selectable input cannot read: in no
     *                           category, yet each a row the run reports
     */
    private function __construct(
        public bool $valid,
        public ?string $error,
        public array $categories,
        public array $lists,
        public bool $supportsSelection,
        #[Hidden]
        public int $unreadable = 0,
    ) {}

    /**
     * An input without a choice to make: every category is imported.
     *
     * @param  list<ManifestCategory>  $categories
     */
    public static function whole(array $categories): self
    {
        return new self(true, null, $categories, [], false);
    }

    /**
     * @param  list<ManifestCategory>  $categories
     * @param  list<ManifestList>  $lists
     */
    public static function selectable(array $categories, array $lists = [], int $unreadable = 0): self
    {
        return new self(true, null, $categories, $lists, true, $unreadable);
    }

    /**
     * Categories none of which is ticked at first: the user picks the ones
     * that are theirs — a media server's log holds the whole household.
     *
     * @param  list<ManifestCategory>  $categories
     */
    public static function pick(array $categories, int $unreadable = 0): self
    {
        return new self(true, null, array_map(fn (ManifestCategory $category): ManifestCategory => $category->optIn(), $categories), [], true, $unreadable);
    }

    /** Not our input; the reason lands verbatim in the task run. */
    public static function invalid(string $error): self
    {
        return new self(false, $error, [], [], false);
    }

    /** How many rows a run over this selection will yield. */
    public function total(ImportSelection $selection): int
    {
        $total = $this->unreadable;

        foreach ($this->categories as $category) {
            if (! $this->supportsSelection || $selection->has($category->key)) {
                $total += $category->count;
            }
        }

        foreach ($this->lists as $list) {
            if ($this->supportsSelection && in_array($list->id, $selection->listIds, true)) {
                $total += $list->itemCount;
            }
        }

        return $total;
    }

    /**
     * @return array{valid: bool, error: string|null, categories: list<array{key: string, label: string, count: int, preselected: bool}>, lists: list<array{id: string, name: string, itemCount: int, isPublic: bool}>, supportsSelection: bool}
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'error' => $this->error,
            'categories' => array_map(fn (ManifestCategory $c) => $c->toArray(), $this->categories),
            'lists' => array_map(fn (ManifestList $l) => $l->toArray(), $this->lists),
            'supportsSelection' => $this->supportsSelection,
        ];
    }
}
