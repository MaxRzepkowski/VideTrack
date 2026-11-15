<?php

use Illuminate\Support\Facades\DB;

/*
 * Every foreign key has an index that starts with it: a cascade or a lookup
 * by the parent otherwise scans the whole child table.
 * Read off the migrated schema, so a new table cannot slip past it.
 */
test('every foreign key leads an index', function () {
    $missing = [];

    foreach (DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'") as $table) {
        $leading = [];

        foreach (DB::select("pragma index_list('{$table->name}')") as $index) {
            $columns = DB::select("pragma index_info('{$index->name}')");
            $leading[] = $columns[0]->name ?? null;
        }

        foreach (DB::select("pragma table_info('{$table->name}')") as $column) {
            if ((int) $column->pk === 1) {
                $leading[] = $column->name;
            }
        }

        foreach (DB::select("pragma foreign_key_list('{$table->name}')") as $foreignKey) {
            if (! in_array($foreignKey->from, $leading, true)) {
                $missing[] = "{$table->name}.{$foreignKey->from}";
            }
        }
    }

    expect($missing)->toBe([]);
});
