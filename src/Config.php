<?php

declare(strict_types=1);

namespace Spiral\Core;

use IteratorAggregate;
use Psr\Container\ContainerInterface;
use Spiral\Core\Internal\Binder;
use Spiral\Core\Internal\Factory;
use Spiral\Core\Internal\Invoker;
use Spiral\Core\Internal\Resolver;
use Spiral\Core\Internal\State;
use Spiral\Core\Internal\Container;
use Traversable;

/**
 * @implements IteratorAggregate<
 *     non-empty-string,
 *     class-string<State>|class-string<ResolverInterface>|class-string<FactoryInterface>|class-string<ContainerInterface>|class-string<BinderInterface>|class-string<InvokerInterface>
 * >
 */
class Config implements IteratorAggregate
{
    /**
     * @param class-string<State> $state
     * @param class-string<ResolverInterface> $resolver
     * @param class-string<FactoryInterface> $factory
     * @param class-string<ContainerInterface> $container
     * @param class-string<BinderInterface> $binder
     * @param class-string<InvokerInterface> $invoker
     */
    public function __construct(
        public readonly string $state = State::class,
        public readonly string $resolver = Resolver::class,
        public readonly string $factory = Factory::class,
        public readonly string $container = Container::class,
        public readonly string $binder = Binder::class,
        public readonly string $invoker = Invoker::class,
    ) {
    }

    public function getIterator(): Traversable
    {
        yield 'state' => $this->state;
        yield 'resolver' => $this->resolver;
        yield 'factory' => $this->factory;
        yield 'container' => $this->container;
        yield 'binder' => $this->binder;
        yield 'invoker' => $this->invoker;
    }
}
