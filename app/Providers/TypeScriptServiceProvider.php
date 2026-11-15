<?php

namespace App\Providers;

use App\Support\TypeScript\AttributedEnumTransformer;
use App\Support\TypeScript\WireShapeTransformer;
use Spatie\LaravelTypeScriptTransformer\TypeScriptTransformerApplicationServiceProvider;
use Spatie\TypeScriptTransformer\Formatters\PrettierFormatter;
use Spatie\TypeScriptTransformer\Transformers\AttributedClassTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Writers\FlatModuleWriter;

/**
 * `php artisan typescript:transform` writes the enums and DTOs marked
 * #[TypeScript] into one module the front imports, so a type the server
 * sends is declared once. A development tool: the production image has no
 * dev packages, and bootstrap/providers.php leaves this out there.
 */
final class TypeScriptServiceProvider extends TypeScriptTransformerApplicationServiceProvider
{
    protected function configure(TypeScriptTransformerConfigFactory $config): void
    {
        $config
            // First: a class that writes its own wire form is that form.
            ->transformer(WireShapeTransformer::class)
            ->transformer(AttributedClassTransformer::class)
            ->transformer(AttributedEnumTransformer::class)
            ->transformDirectories(app_path())
            // The check writes next to the repository and compares.
            ->outputDirectory(getenv('TYPESCRIPT_OUTPUT_DIRECTORY') ?: resource_path('js/types'))
            ->writer(new FlatModuleWriter('generated.ts'))
            ->formatter(PrettierFormatter::class)
            ->withoutManifest();
    }
}
