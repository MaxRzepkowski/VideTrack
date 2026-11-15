<?php

namespace App\Integrations\VideTrack\Backup;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Writes a user's whole library as a backup file, entry by entry, so the
 * download starts at once and a long history never sits in memory whole.
 * One entry per line keeps the file readable and diffable.
 */
final readonly class BackupWriter
{
    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public function __construct(private BackupEntries $entries) {}

    /**
     * Read in one transaction: the sections come out of one picture of the
     * library, so a play an import or a media server writes meanwhile cannot
     * shift the pages and show up twice or not at all.
     *
     * @param  resource  $out
     */
    public function write(User $user, $out): void
    {
        DB::transaction(fn () => $this->entries($user, $out));
    }

    /**
     * @param  resource  $out
     */
    private function entries(User $user, $out): void
    {
        fwrite($out, '{'.$this->field('format', BackupFormat::NAME).','
            .$this->field('version', BackupFormat::VERSION).','
            .$this->field('exported_at', now()->toIso8601String()).','
            .$this->field('username', $user->username));

        foreach (BackupSection::cases() as $section) {
            fwrite($out, ",\n".json_encode($section->value, self::JSON).': [');
            $first = true;

            foreach ($this->entries->section($user, $section) as $entry) {
                fwrite($out, ($first ? "\n  " : ",\n  ").json_encode($entry->toArray(), self::JSON));
                $first = false;
            }

            fwrite($out, $first ? ']' : "\n]");
        }

        fwrite($out, "\n}\n");
    }

    private function field(string $key, string|int $value): string
    {
        return "\n".json_encode($key, self::JSON).': '.json_encode($value, self::JSON);
    }
}
