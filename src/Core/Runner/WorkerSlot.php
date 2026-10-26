<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function sprintf;
use function strval;

/**
 * One of the places a runner runs its processes side by side in, which tells
 * each process it starts there which it is as paratest tells its workers: that
 * it is a parallel worker, as `PARATEST` and Laravel's
 * `LARAVEL_PARALLEL_TESTING` say, the place's number from one, and a token no
 * other place of any run shares, so a project's tests can keep a database or a
 * directory of their own. A runner that runs one process at a time tells it
 * nothing.
 */
final readonly class WorkerSlot
{
    /** What a flag that a process is a parallel worker holds, as paratest and Laravel set it. */
    private const string ON = '1';

    private function __construct(private Environment $told)
    {
    }

    /**
     * A place among several, by its number from one, in a run that names
     * itself by a token of its own.
     *
     * @param positive-int $number
     */
    public static function of(int $number, string $run): self
    {
        return new self(Environment::telling(WorkerVariable::Paratest->value, self::ON)
            ->and(Environment::telling(WorkerVariable::TestToken->value, strval($number)))
            ->and(Environment::telling(WorkerVariable::UniqueTestToken->value, sprintf('%d_%s', $number, $run)))
            ->and(Environment::telling(WorkerVariable::LaravelParallelTesting->value, self::ON)));
    }

    /** The one place of a runner that runs one process at a time. */
    public static function alone(): self
    {
        return new self(Environment::none());
    }

    /** What a process started here is told. */
    public function variables(): Environment
    {
        return $this->told;
    }
}
