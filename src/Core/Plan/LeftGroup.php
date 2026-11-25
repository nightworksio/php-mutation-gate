<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_diff_key;
use function array_key_exists;
use function array_keys;
use function max;

/**
 * Mutants a shard left at its deadline that a job of the second wave judges
 * together (ADR-0190), priced as its split streams run them (ADR-0195): the
 * test files covering any of them, each once, the longest of them, the
 * seconds of the forks at the ledger's likely killers, and the seconds of
 * the covering tests of the mutants that have none; a source file's left
 * mutants, one of them, or a share of them.
 *
 * @internal
 */
final readonly class LeftGroup
{
    /**
     * @param list<LeftMutant>    $mutants
     * @param array<string, bool> $files   the test files covering any of the mutants, by their names
     */
    private function __construct(
        private array $mutants,
        private array $files,
        private float $seconds,
        private float $longest,
        private float $killers,
        private float $covering,
        private float $alone = 0.0,
    ) {
    }

    /**
     * These mutants, covered by these test files, priced by each file's
     * seconds, their forks at their likely killers and their covering tests.
     *
     * @param list<LeftMutant>     $mutants
     * @param list<string>         $covering the test files covering any of them
     * @param array<string, float> $timed    the seconds the map timed each test file's tests at together, by the file
     */
    public static function of(array $mutants, array $covering, array $timed, LeftForks $forks): self
    {
        $files = [];

        foreach ($covering as $file) {
            $files[$file] = true;
        }

        $none = new self([], [], 0.0, 0.0, 0.0, 0.0);
        $seconds = $none->added(new self([], $files, 0.0, 0.0, 0.0, 0.0), $timed);
        $longest = 0.0;

        foreach (array_keys($files) as $file) {
            $longest = max($longest, array_key_exists($file, $timed) ? $timed[$file] : 0.0);
        }

        return new self($mutants, $files, $seconds, $longest, $forks->killers, $forks->covering, $forks->alone);
    }

    /** @return list<LeftMutant> */
    public function mutants(): array
    {
        return $this->mutants;
    }

    /** The wall seconds a job's first pass takes over these mutants. */
    public function first(StreamWall $wall): float
    {
        return $wall->first($this->seconds, $this->longest, $this->killers);
    }

    /** The wall seconds a job's second pass takes over these mutants. */
    public function second(StreamWall $wall): float
    {
        return $wall->second($this->covering);
    }

    /** The wall seconds a job takes over these mutants: both passes, and the runs of those no stream judges. */
    public function wall(StreamWall $wall): float
    {
        return $this->first($wall) + $this->second($wall) + $wall->alone($this->alone);
    }

    /**
     * The test files covering any of its mutants, by their names.
     *
     * @return array<string, bool>
     */
    public function files(): array
    {
        return $this->files;
    }

    public function seconds(): float
    {
        return $this->seconds;
    }

    public function longest(): float
    {
        return $this->longest;
    }

    public function killers(): float
    {
        return $this->killers;
    }

    public function covering(): float
    {
        return $this->covering;
    }

    public function alone(): float
    {
        return $this->alone;
    }

    /**
     * The seconds of the test files covering another group's mutants that this one does not run yet.
     *
     * @param array<string, float> $timed
     */
    public function added(self $other, array $timed): float
    {
        return self::addedTo($this->files, $other, $timed);
    }

    /**
     * The seconds of the test files covering a group's mutants that these files do not hold.
     *
     * @param array<string, bool>  $files
     * @param array<string, float> $timed
     */
    public static function addedTo(array $files, self $other, array $timed): float
    {
        $seconds = 0.0;

        foreach (array_keys(array_diff_key($other->files, $files)) as $file) {
            $seconds += array_key_exists($file, $timed) ? $timed[$file] : 0.0;
        }

        return $seconds;
    }
}
