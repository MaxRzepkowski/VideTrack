<?php

use Illuminate\Support\Facades\DB;

/*
 * The orphan sweep removes catalog rows nothing points at; what points at
 * one is read off the schema here, so a new table with a key to the catalog
 * cannot be forgotten by it. Each key is either something the sweep asks of
 * a row before it goes (a holding, a list entry, a bell, a credit), or the
 * row's own technical data, which goes with it.
 */
test('every key pointing at the catalog is asked by the orphan sweep or goes with the row', function () {
    $asked = [
        'media_items' => ['plays', 'ratings', 'favorites', 'media_user_states', 'watch_cycles', 'follows', 'list_items', 'library_entries'],
        'people' => ['credits', 'follows', 'follow_person', 'list_items'],
        'companies' => ['follows', 'follow_company', 'list_items'],
        'lists' => ['list_items', 'follows', 'follow_list'],
    ];
    $goesWithTheRow = [
        'media_items' => ['media_items', 'credits', 'title_refreshes', 'title_relations', 'title_relation_checks', 'title_franchises'],
        'people' => [],
        'companies' => [],
        'lists' => [],
    ];

    $found = [];

    foreach (DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'") as $table) {
        foreach (DB::select("pragma foreign_key_list('{$table->name}')") as $key) {
            if (array_key_exists($key->table, $asked)) {
                $found[$key->table][] = $table->name;

                if (! in_array($table->name, $asked[$key->table], true)) {
                    expect($key->on_delete)->toBe('CASCADE', "{$table->name}.{$key->from} points at {$key->table}: the sweep must ask it, or it must go with the row");
                }
            }
        }
    }

    foreach ($asked as $catalog => $tables) {
        expect(array_values(array_unique($found[$catalog] ?? [])))->toEqualCanonicalizing(array_values(array_unique([...$tables, ...$goesWithTheRow[$catalog]])));
    }
});
