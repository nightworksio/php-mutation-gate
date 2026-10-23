<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpSpec;

use function array_key_exists;
use function file_put_contents;

use NightWorksIO\MutationGate\Core\Native\Outcome;
use NightWorksIO\MutationGate\Core\Native\Selected;
use PhpSpec\Event\ExampleEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Appends to the results file each example the run selects as it starts,
 * before anything of it runs, and how it ended: passed, failed, broken,
 * which the gate reads as errored, or neither, skipped or pending. An
 * example that started and ended none of these ways is one whose process
 * died while it ran.
 */
final readonly class Recording implements EventSubscriberInterface
{
    /** How each result PhpSpec gives an example that passed, failed or broke ended; any other ended neither way. */
    private const array OUTCOMES = [
        ExampleEvent::PASSED => Outcome::Passed,
        ExampleEvent::FAILED => Outcome::Failed,
        ExampleEvent::BROKEN => Outcome::Errored,
    ];

    public function __construct(private string $results, private Selected $selected)
    {
    }

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return ['beforeExample' => 'started', 'afterExample' => 'ended'];
    }

    public function started(ExampleEvent $event): void
    {
        $example = Example::of($event->getExample());

        if ($this->selected->selects($example)) {
            $this->write(Outcome::Started->line($example->key()));
        }
    }

    public function ended(ExampleEvent $event): void
    {
        $example = Example::of($event->getExample());

        if ($this->selected->selects($example)) {
            $this->write($this->outcomeOf($event->getResult())->line($example->key()));
        }
    }

    private function outcomeOf(int $result): Outcome
    {
        return array_key_exists($result, self::OUTCOMES) ? self::OUTCOMES[$result] : Outcome::Neither;
    }

    private function write(string $line): void
    {
        file_put_contents($this->results, $line, FILE_APPEND | LOCK_EX);
    }
}
