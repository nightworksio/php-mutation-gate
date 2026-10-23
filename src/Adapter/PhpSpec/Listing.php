<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpSpec;

use function file_put_contents;

use NightWorksIO\MutationGate\Core\Native\Candidate;
use NightWorksIO\MutationGate\Core\Native\ListedTests;
use PhpSpec\Event\ExampleEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Lists every example of the suite and the holding groups each is in, in
 * the order PhpSpec loads them, and writes the list once the suite is done
 * (see ListedTests). The run selects no example, so none runs.
 */
final class Listing implements EventSubscriberInterface
{
    /** @var list<Candidate> */
    private array $examples = [];

    public function __construct(private readonly string $file)
    {
    }

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return ['beforeExample' => 'listed', 'afterSuite' => 'written'];
    }

    public function listed(ExampleEvent $event): void
    {
        $this->examples[] = Example::of($event->getExample());
    }

    public function written(): void
    {
        file_put_contents($this->file, ListedTests::encoded(...$this->examples));
    }
}
