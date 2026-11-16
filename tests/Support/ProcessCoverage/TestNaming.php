<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support\ProcessCoverage;

use function constant;
use function defined;
use function is_string;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

use function putenv;
use function sprintf;

/**
 * Where prepend.php defines PROCESS_COVERAGE_NAMING in the suite's own
 * processes that run tests, each test named, while it runs, in the variable
 * that constant names, so every process the test starts records its lines
 * as its own. A parallel run's own process runs none, and a subscriber
 * there fails the run.
 */
final readonly class TestNaming implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $naming = defined('PROCESS_COVERAGE_NAMING') ? constant('PROCESS_COVERAGE_NAMING') : null;

        if (! is_string($naming)) {
            return;
        }

        $facade->registerSubscribers(
            new readonly class ($naming) implements PreparationStartedSubscriber {
                public function __construct(private string $naming)
                {
                }

                /** Names the test to every process it starts. */
                public function notify(PreparationStarted $event): void
                {
                    putenv(sprintf('%s=%s', $this->naming, $event->test()->id()));
                }
            },
            new readonly class ($naming) implements FinishedSubscriber {
                public function __construct(private string $naming)
                {
                }

                /** Names no test once it has finished. */
                public function notify(Finished $event): void
                {
                    putenv($this->naming);
                }
            },
        );
    }
}
