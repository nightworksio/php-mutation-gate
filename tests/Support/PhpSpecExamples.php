<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function dirname;

use LogicException;
use NightWorksIO\MutationGate\Tests\Fixtures\PhpSpec\HeldSpec;
use PhpSpec\Console\ContainerAssembler;
use PhpSpec\Event\ExampleEvent;
use PhpSpec\Formatter\Presenter\Presenter;
use PhpSpec\Loader\Node\ExampleNode;
use PhpSpec\Loader\Node\SpecificationNode;
use PhpSpec\Locator\PSR0\PSR0Locator;
use PhpSpec\Locator\PSR0\PSR0Resource;
use PhpSpec\ServiceContainer\IndexedServiceContainer;
use PhpSpec\Specification;
use PhpSpec\Util\Filesystem;
use ReflectionClass;
use ReflectionMethod;

use function sprintf;

/** The examples of the spec fixture, as PhpSpec's loader makes them, and the events PhpSpec dispatches of them. */
final class PhpSpecExamples
{
    /** The id the PhpSpec runner gives an example of HeldSpec, by its method. */
    public static function id(string $method): string
    {
        return sprintf('%s::%s', HeldSpec::class, $method);
    }

    /** An example of HeldSpec, by its method, in its spec, as PhpSpec loads it. */
    public static function held(string $method): ExampleNode
    {
        $example = new ExampleNode($method, new ReflectionMethod(HeldSpec::class, $method));
        self::specification(HeldSpec::class)->addExample($example);

        return $example;
    }

    /** The event PhpSpec dispatches as an example of HeldSpec ends with this result, taking this long. */
    public static function event(string $method, int $result = ExampleEvent::PASSED, float $time = 0.0): ExampleEvent
    {
        return new ExampleEvent(self::held($method), $time, $result);
    }

    /** PhpSpec's presenter, as its container builds it, which its matchers and collaborators are made with. */
    public static function presenter(): Presenter
    {
        $container = new IndexedServiceContainer();
        new ContainerAssembler()->build($container);
        $presenter = $container->get('formatter.presenter');

        return $presenter instanceof Presenter ? $presenter : throw new LogicException('PhpSpec builds its presenter.');
    }

    /**
     * A spec, as PhpSpec's loader makes it of its class, located in the fixture's directory.
     *
     * @param class-string<Specification> $class
     */
    private static function specification(string $class): SpecificationNode
    {
        $directory = sprintf('%s/Fixtures/PhpSpec', dirname(__DIR__));
        $locator = new PSR0Locator(new Filesystem(), '', 'spec', $directory, $directory);

        return new SpecificationNode('Held', new ReflectionClass($class), new PSR0Resource(['Held'], $locator));
    }
}
