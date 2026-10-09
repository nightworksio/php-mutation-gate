<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Migration;

use function array_values;

use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\JsonDocument;
use NightWorksIO\MutationGate\Core\NotGiven;
use Traversable;

/**
 * What the gate's releases changed in what a file writes, the oldest first
 * (ADR-0026). Every key a release ever renamed or removed stays known here,
 * so a file that still writes one is read as the file of its release.
 *
 * @implements IteratorAggregate<int, Retired>
 */
final readonly class Migrations implements IteratorAggregate
{
    /** @param list<Migration> $migrations */
    private function __construct(private array $migrations)
    {
    }

    /** These releases' changes, the oldest release first. */
    public static function of(Migration ...$migrations): self
    {
        return new self(array_values($migrations));
    }

    /** What the gate's releases changed in what a config writes; none before a key is first renamed or removed. */
    public static function config(): self
    {
        return new self([]);
    }

    /** What the gate's releases changed in what a baseline writes; none before its format first changes. */
    public static function baseline(): self
    {
        return new self([]);
    }

    /** The file with every change made that applies to it, the oldest first. */
    public function applied(JsonDocument $file): JsonDocument
    {
        foreach ($this as $retired) {
            $file = $retired->step()->applied($file);
        }

        return $file;
    }

    /** Each change the file still needs, at what it retired, naming `migrate`; nothing where it needs none. */
    public function pending(JsonDocument $file): Invalid|NotGiven
    {
        $problems = [];

        foreach ($this as $retired) {
            $problems = $retired->step()->appliesTo($file)
                ? [...$problems, Problem::at($retired->step()->at()->value(), $retired->pending())]
                : $problems;
        }

        return $problems === [] ? NotGiven::value() : Invalid::because(...$problems);
    }

    /** Each change a migrated file still needs, which only a hand edit can make; nothing where none is left. */
    public function left(JsonDocument $migrated): Invalid|NotGiven
    {
        $problems = [];

        foreach ($this as $retired) {
            $problems = $retired->step()->appliesTo($migrated)
                ? [...$problems, Problem::at($retired->step()->at()->value(), $retired->byHand())]
                : $problems;
        }

        return $problems === [] ? NotGiven::value() : Invalid::because(...$problems);
    }

    /** @return Traversable<int, Retired> */
    public function getIterator(): Traversable
    {
        foreach ($this->migrations as $migration) {
            foreach ($migration as $step) {
                yield Retired::of($step, $migration);
            }
        }
    }
}
