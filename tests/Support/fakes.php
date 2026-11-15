<?php

/**
 * Puts every fake in tests/Fakes back to the state its class declares. A fake
 * the app builds itself (a registered source, a connection service) can only
 * hear from a test through static fields, and left alone, what one test told
 * it would reach the next — a result that hangs on the order tests run in.
 */
function resetFakes(): void
{
    foreach (glob(__DIR__.'/../Fakes/*.php') ?: [] as $file) {
        $class = new ReflectionClass('Tests\\Fakes\\'.basename($file, '.php'));

        foreach ($class->getProperties(ReflectionProperty::IS_STATIC) as $field) {
            if ($field->getDeclaringClass()->getName() === $class->getName() && $field->hasDefaultValue()) {
                $field->setValue(null, $field->getDefaultValue());
            }
        }
    }
}
