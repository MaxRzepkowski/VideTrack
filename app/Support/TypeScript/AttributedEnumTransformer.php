<?php

namespace App\Support\TypeScript;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Data\TransformationContext;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\Transformed\Untransformable;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;

/**
 * Only the enums marked #[TypeScript] reach the front — what the client sees
 * is a choice, and enums of different modules may share a short name.
 */
final class AttributedEnumTransformer extends EnumTransformer
{
    public function transform(PhpClassNode $phpClassNode, TransformationContext $context): Transformed|Untransformable
    {
        return $phpClassNode->getAttributes(TypeScript::class) === []
            ? Untransformable::create()
            : parent::transform($phpClassNode, $context);
    }
}
