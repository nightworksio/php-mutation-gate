<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_diff_key;
use function array_key_exists;
use function array_keys;

/**
 * Mutants a shard left at its deadline that a job of the second wave judges
 * together (ADR-0190), with the test files covering any of them and the
 * seconds the map timed every test of those files at, each file once: a
 * source file's left mutants, or a share of them.
 *
 * @internal
 */
final readonly class LeftGroup
{
    /**
     * @param list<LeftMutant>    $mutants
     * @param array<string, bool> $files   the test files covering any of the mutants, by their names
     */
    private function __construct(private array $mutants, private array $files, private float $seconds)
    {
    }

    /**
     * These mutants, covered by these test files, priced by each file's seconds.
     *
     * @param list<LeftMutant>     $mutants
     * @param list<string>         $covering the test files covering any of them
     * @param array<string, float> $timed    the seconds the map timed each test file's tests at together, by the file
     */
    public static function of(array $mutants, array $covering, array $timed): self
    {
        $empty = new self([], [], 0.0);
        $files = [];

        foreach ($covering as $file) {
            $files[$file] = true;
        }

        return new self($mutants, $files, $empty->added(new self([], $files, 0.0), $timed));
    }

    /** @return list<LeftMutant> */
    public function mutants(): array
    {
        return $this->mutants;
    }

    public function seconds(): float
    {
        return $this->seconds;
    }

    /**
     * The seconds of the test files covering another group's mutants that this one does not run yet.
     *
     * @param array<string, float> $timed
     */
    public function added(self $other, array $timed): float
    {
        $seconds = 0.0;

        foreach (array_keys(array_diff_key($other->files, $this->files)) as $file) {
            $seconds += array_key_exists($file, $timed) ? $timed[$file] : 0.0;
        }

        return $seconds;
    }

    /**
     * This group with another's mutants after its own, and that many seconds more.
     */
    public function joined(self $other, float $added): self
    {
        return new self([...$this->mutants, ...$other->mutants], $this->files + $other->files, $this->seconds + $added);
    }
}
