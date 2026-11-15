<?php

namespace App\Support\TypeScript;

use JsonSerializable;
use LogicException;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Spatie\TypeScriptTransformer\Actions\FindClassNameFqcnAction;
use Spatie\TypeScriptTransformer\Data\TransformationContext;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\Transformers\AttributedClassTransformer;
use Spatie\TypeScriptTransformer\TypeResolvers\Data\ParsedClass;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNode;

/**
 * A class that writes its own wire form reaches the front as that form: the
 * `@return array{…}` of its `jsonSerialize()`, not its properties, which
 * describe the PHP object rather than what a page receives. PHPStan holds
 * the description to the method's body, so the generated type follows what
 * is sent. A marked class without the description fails the generation
 * instead of producing a type that only looks right.
 */
final class WireShapeTransformer extends AttributedClassTransformer
{
    private readonly WireShapeTranspiler $wire;

    private readonly Lexer $lexer;

    private readonly PhpDocParser $docs;

    private readonly FindClassNameFqcnAction $classNames;

    public function __construct()
    {
        parent::__construct();

        $config = new ParserConfig(usedAttributes: []);
        $constants = new ConstExprParser($config);
        $this->wire = new WireShapeTranspiler;
        $this->lexer = new Lexer($config);
        $this->docs = new PhpDocParser($config, new TypeParser($config, $constants), $constants);
        $this->classNames = new FindClassNameFqcnAction;
    }

    protected function shouldTransform(PhpClassNode $phpClassNode): bool
    {
        return parent::shouldTransform($phpClassNode) && $phpClassNode->implementsInterface(JsonSerializable::class);
    }

    protected function getTypeScriptNode(
        PhpClassNode $phpClassNode,
        TransformationContext $context,
        ?ParsedClass $parsedClass = null,
    ): TypeScriptNode {
        $method = $phpClassNode->getMethod('jsonSerialize');
        $shape = $method === null ? null : $this->docTypeResolver->method($method)?->returnType;

        if (! $shape instanceof ArrayShapeNode) {
            throw new LogicException("{$phpClassNode->getName()}::jsonSerialize() must describe what it sends as `@return array{…}`.");
        }

        return $this->wire->shape($shape, $phpClassNode, $this->aliases(...));
    }

    /**
     * The class's own `@phpstan-type`s and the ones it imports, each with the
     * class that declares it.
     *
     * @return array<string, array{TypeNode, PhpClassNode}>
     */
    private function aliases(PhpClassNode $class): array
    {
        $doc = $this->doc($class);

        if ($doc === null) {
            return [];
        }

        $aliases = [];

        foreach ($doc->getTypeAliasTagValues() as $tag) {
            $aliases[$tag->alias] = [$tag->type, $class];
        }

        foreach ($doc->getTypeAliasImportTagValues() as $import) {
            $from = $import->importedFrom instanceof IdentifierTypeNode
                ? $this->classNames->execute($class, $import->importedFrom->name)
                : null;

            $aliases[$import->importedAs ?? $import->importedAlias] = $from === null || ! class_exists($from)
                ? throw new LogicException("{$class->getName()} imports {$import->importedAlias} from a class that cannot be found.")
                : ($this->aliases(PhpClassNode::fromClassString($from))[$import->importedAlias]
                    ?? throw new LogicException("{$from} has no type alias {$import->importedAlias}."));
        }

        return $aliases;
    }

    private function doc(PhpClassNode $class): ?PhpDocNode
    {
        $comment = $class->getDocComment();

        return $comment === null ? null : $this->docs->parse(new TokenIterator($this->lexer->tokenize($comment)));
    }
}
