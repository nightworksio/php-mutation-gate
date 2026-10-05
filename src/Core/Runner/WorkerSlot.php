<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_map;
use function range;
use function sprintf;
use function strval;

/**
 * One of the places a runner runs its processes side by side in, which tells
 * each process it starts there which it is as paratest tells its workers: the
 * place's number from one, and a token no other place of any run shares, so a
 * project's tests can keep a database or a directory of their own. A runner
 * that runs one process at a time tells it nothing.
 */
final readonly class WorkerSlot
{
    /** @param positive-int|NotShared $number */
    private function __construct(private int|NotShared $number, private string $run)
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
        return new self($number, $run);
    }

    /** The one place of a runner that runs one process at a time. */
    public static function alone(): self
    {
        return new self(NotShared::Place, '');
    }

    /**
     * The places these processes run in, for a run that names itself by a
     * token of its own: one for each, numbered from one, or the one place
     * that tells its process nothing where there is one process.
     *
     * @return non-empty-list<self>
     */
    public static function eachOf(Processes $processes, string $run): array
    {
        $count = $processes->count();

        return $count === 1
            ? [self::alone()]
            : array_map(static fn(int $number): self => self::of($number, $run), range(1, $count));
    }

    /** @return array<string, string> the variables a process started here is told, by their names */
    public function variables(): array
    {
        return $this->number instanceof NotShared ? [] : [
            WorkerVariable::TestToken->value => strval($this->number),
            WorkerVariable::UniqueTestToken->value => sprintf('%d_%s', $this->number, $this->run),
        ];
    }
}
