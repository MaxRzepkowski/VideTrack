<?php

namespace App\Console\Commands;

use App\Support\LangCheck;
use App\Support\LangFiles;
use Illuminate\Console\Command;

final class LangCheckCommand extends Command
{
    protected $signature = 'lang:check';

    protected $description = 'Check the translations: the same keys in every locale, every key the code names exists, and no key of ours goes unused';

    public function handle(): int
    {
        $problems = (new LangCheck(new LangFiles(lang_path()), [app_path(), resource_path('js'), resource_path('views')]))->problems();

        foreach ($problems as $problem) {
            $this->line($problem);
        }

        if ($problems !== []) {
            $this->error(count($problems).' translation problem(s).');

            return self::FAILURE;
        }

        $this->info('Translations are in order.');

        return self::SUCCESS;
    }
}
