<?php

use App\Support\ServiceRegistry;
use Illuminate\Container\Container;

function thingRegistry(Container $container): ServiceRegistry
{
    return new class($container) extends ServiceRegistry
    {
        protected function kind(): string
        {
            return 'thing';
        }
    };
}

test('a code hands out what the container binds under its class', function () {
    $container = new Container;
    $bound = new ArrayObject(['fake' => true]);
    $container->instance(ArrayObject::class, $bound);
    $registry = thingRegistry($container);
    $registry->register('thing', ArrayObject::class);

    expect($registry->get('thing'))->toBe($bound);
});

test('a binding that hands out another class breaks the registry\'s promise', function () {
    $container = new Container;
    $container->bind(ArrayObject::class, fn (): stdClass => new stdClass);
    $registry = thingRegistry($container);
    $registry->register('thing', ArrayObject::class);

    $registry->get('thing');
})->throws(LogicException::class, 'resolved [ArrayObject] to another class');
