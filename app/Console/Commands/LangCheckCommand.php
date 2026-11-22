<?php

namespace App\Console\Commands;

use App\Support\LangCheck;
use App\Support\LangFiles;
use App\Support\Locales;
use Illuminate\Console\Command;

final class LangCheckCommand extends Command
{
    protected $signature = 'lang:check';

    protected $description = 'Check the translations: the same keys in the complete locales, every key the code names exists, no key of ours goes unused, and what the other locales translate fits its source';

    public function handle(): int
    {
        $check = new LangCheck(new LangFiles(lang_path()), Locales::fallback(), [app_path(), resource_path('js'), resource_path('views')]);
        $problems = $check->problems();

        foreach ($problems as $problem) {
            $this->line($problem);
        }

        // A language that may lag behind says how far it is, without failing.
        foreach ($check->coverage() as $locale => ['translated' => $translated, 'total' => $total]) {
            $this->line("{$locale}: {$translated} of {$total} texts translated");
        }

        if ($problems !== []) {
            $this->error(count($problems).' translation problem(s).');

            return self::FAILURE;
        }

        $this->info('Translations are in order.');

        return self::SUCCESS;
    }
}
