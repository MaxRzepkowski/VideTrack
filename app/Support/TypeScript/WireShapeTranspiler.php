<?php

namespace App\Support\TypeScript;

use Closure;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use ReflectionEnum;
use Spatie\TypeScriptTransformer\Actions\TranspilePhpStanTypeToTypeScriptNodeAction;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\References\ClassStringReference;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptIndexSignature;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNode;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptObject;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptProperty;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptReference;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptString;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptUnknown;

/**
 * A wire description in PHPStan's words, as TypeScript. Where the stock
 * transpiler fits a PHP object, this one fits what `json_encode` sends:
 * `mixed` is `unknown` (the front has to look before it reads), an open
 * shape keeps its open rest, a value of an enum the front knows is that
 * enum's type, and type aliases are followed — each read in the class that
 * declares it, where its class names mean what they say.
 */
final class WireShapeTranspiler extends TranspilePhpStanTypeToTypeScriptNodeAction
{
    /** @var array<string, array{TypeNode, PhpClassNode}> the aliases in scope, each with the class declaring it */
    private array $aliases = [];

    /** @var Closure(PhpClassNode): array<string, array{TypeNode, PhpClassNode}> */
    private Closure $aliasesOf;

    /**
     * @param  Closure(PhpClassNode): array<string, array{TypeNode, PhpClassNode}>  $aliasesOf  a class's `@phpstan-type`s, imported ones included
     */
    public function shape(TypeNode $type, PhpClassNode $class, Closure $aliasesOf): TypeScriptNode
    {
        $this->aliasesOf = $aliasesOf;
        $this->aliases = $aliasesOf($class);

        return $this->execute($type, $class);
    }

    protected function identifierNode(IdentifierTypeNode $node, ?PhpClassNode $phpClassNode): TypeScriptNode
    {
        if (isset($this->aliases[$node->name])) {
            [$type, $declaring] = $this->aliases[$node->name];
            $outer = $this->aliases;
            $this->aliases = ($this->aliasesOf)($declaring);

            try {
                return $this->resolve($type, $declaring);
            } finally {
                $this->aliases = $outer;
            }
        }

        return $node->name === 'mixed' ? new TypeScriptUnknown : parent::identifierNode($node, $phpClassNode);
    }

    protected function arrayShapeNode(ArrayShapeNode|ObjectShapeNode $node, ?PhpClassNode $phpClassNode): TypeScriptObject
    {
        $object = parent::arrayShapeNode($node, $phpClassNode);

        foreach ($object->properties as $property) {
            $property->isReadonly = true;
        }

        if ($node instanceof ArrayShapeNode && ! $node->sealed) {
            $object->properties[] = new TypeScriptProperty(
                new TypeScriptIndexSignature(new TypeScriptString, 'key'),
                $node->unsealedType === null ? new TypeScriptUnknown : $this->resolve($node->unsealedType->valueType, $phpClassNode),
                isReadonly: true,
            );
        }

        return $object;
    }

    /**
     * A short name the class's file imports means that import. The stock
     * lookup asks for a global class of the name first, so a global one
     * (`Directory`, or `SortDirection` from PHP 8.6 and its polyfill) would
     * shadow the imported enum.
     */
    protected function resolveClass(string $className, ?PhpClassNode $phpClassNode): ?string
    {
        if ($phpClassNode !== null && ! str_contains($className, '\\')) {
            $imported = $this->findClassNameFqcnAction->execute($phpClassNode, $className);

            if ($imported !== null && $imported !== $className && (class_exists($imported) || interface_exists($imported))) {
                return $imported;
            }
        }

        return parent::resolveClass($className, $phpClassNode);
    }

    protected function genericNode(GenericTypeNode $node, ?PhpClassNode $phpClassNode): TypeScriptNode
    {
        $of = $node->genericTypes[0] ?? null;
        $enum = $node->type instanceof IdentifierTypeNode && $node->type->name === 'value-of' && $of instanceof IdentifierTypeNode
            ? $this->resolveClass($of->name, $phpClassNode)
            : null;

        // The front has the values of an enum it knows as a named type; the
        // stock transpiler would spell them out again.
        if ($enum !== null && enum_exists($enum) && (new ReflectionEnum($enum))->getAttributes(TypeScript::class) !== []) {
            return new TypeScriptReference(new ClassStringReference($enum));
        }

        return parent::genericNode($node, $phpClassNode);
    }
}
