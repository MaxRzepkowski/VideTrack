<?php

namespace App\Integrations\Import;

use App\Integrations\Import\Data\ImportListRef;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ManifestList;

/**
 * The lists of the user's own making an export carries on its titles —
 * shelves, collections, tags — offered opt-in by name. The analysis counts
 * the titles on each; the run reads back the ones picked, under the key a
 * title's list name is looked up by.
 */
final class OwnLists
{
    /** @var array<string, int> */
    private array $counts = [];

    /** One more title on the list of that name. */
    public function count(string $name): void
    {
        $key = self::key($name);

        if ($key !== '') {
            $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
        }
    }

    /** @return list<ManifestList> */
    public function offered(): array
    {
        $lists = [];

        foreach ($this->counts as $key => $count) {
            $lists[] = new ManifestList((string) $key, (string) $key, $count, false);
        }

        return $lists;
    }

    /**
     * The lists the user picked, by key.
     *
     * @return array<string, ImportListRef>
     */
    public static function picked(ImportSelection $selection): array
    {
        $lists = [];

        foreach ($selection->listIds as $key) {
            $lists[$key] = new ImportListRef($key);
        }

        return $lists;
    }

    /** The key a list name from the file goes by: a selection carries it back. */
    public static function key(string $name): string
    {
        return ImportSelection::key(trim($name));
    }
}
