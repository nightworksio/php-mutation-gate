<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use Closure;

use function file_put_contents;
use function getenv;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Core\Mutant\OrderDigest;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\WholeNumber;
use PHPUnit\Event\EventFacadeIsSealedException;
use PHPUnit\Event\Facade;
use PHPUnit\Event\UnknownSubscriberTypeException;
use PHPUnit\TestRunner\TestResult\Facade as TestResults;

/**
 * Where a replay of a kill's own run stops (ADR-0004): once as many tests
 * have finished as the run ran before its last killer, it records how many,
 * and the digest of the order it started them in, by the copy it serves,
 * and asks PHPUnit to run no more, as a signal does. A process the adapter
 * names no such count for runs every test.
 */
final class ReplayStop
{
    private int $finished = 0;

    private OrderDigest $order;

    /** @param Closure(): void $interrupt how PHPUnit is asked to run no more tests */
    private function __construct(
        private readonly string $results,
        private readonly string $mutated,
        private readonly int $after,
        private readonly Closure $interrupt,
    ) {
        $this->order = OrderDigest::start();
    }

    /** Stopping where the adapter names a count, a results file and a copy, subscribed to PHPUnit's events. */
    public static function fromEnvironment(Facade $events): self|Off
    {
        return self::listening(
            getenv(GateVariable::StopAfter->value),
            getenv(GateVariable::Results->value),
            getenv(Recorder::MUTATED),
            $events,
        );
    }

    /** @param Closure(): void $interrupt how PHPUnit is asked to run no more tests: as a signal does, by default */
    public static function listening(
        string|false $after,
        string|false $results,
        string|false $mutated,
        Facade $events,
        Closure $interrupt = TestResults::interrupt(...),
    ): self|Off {
        $named = is_string($after) && WholeNumber::isPositive($after)
            && is_string($results) && $results !== '' && is_string($mutated) && $mutated !== '';

        if (! $named) {
            return Off::Stopping;
        }

        $stop = new self($results, $mutated, (int) $after, $interrupt);

        try {
            $events->registerSubscribers(new OnReplayStarted($stop), new OnReplayFinished($stop));
        } catch (EventFacadeIsSealedException|UnknownSubscriberTypeException) {
            return Off::Stopping;
        }

        return $stop;
    }

    /** A test that started, by its id. */
    public function started(string $test): void
    {
        $this->order = $this->order->with(TestId::of($test));
    }

    /** A test that finished: the last one the replay runs, where it is the one it stops after. */
    public function finished(): void
    {
        $this->finished++;

        if ($this->finished === $this->after) {
            file_put_contents(
                $this->results,
                RecordLine::stopped($this->mutated, $this->finished, $this->order->value()),
                FILE_APPEND | LOCK_EX,
            );
            ($this->interrupt)();
        }
    }
}
