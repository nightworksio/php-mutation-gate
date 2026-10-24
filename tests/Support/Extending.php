<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use PHPUnit\Event\Facade as Events;
use PHPUnit\Event\Subscriber;
use PHPUnit\Event\Tracer\Tracer;
use PHPUnit\Runner\Extension\Facade;

/** The facade PHPUnit hands an extension as it boots, over a facade of PHPUnit's events of a test's own. */
final readonly class Extending implements Facade
{
    private function __construct(private Events $events)
    {
    }

    public static function over(Events $events): self
    {
        return new self($events);
    }

    public function registerSubscribers(Subscriber ...$subscribers): void
    {
        foreach ($subscribers as $subscriber) {
            $this->events->registerSubscriber($subscriber);
        }
    }

    public function registerSubscriber(Subscriber $subscriber): void
    {
        $this->events->registerSubscriber($subscriber);
    }

    public function registerTracer(Tracer $tracer): void
    {
        $this->events->registerTracer($tracer);
    }

    public function replaceOutput(): void
    {
    }

    public function replaceProgressOutput(): void
    {
    }

    public function replaceResultOutput(): void
    {
    }

    public function requireCodeCoverageCollection(): void
    {
    }
}
