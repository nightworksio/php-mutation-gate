<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function array_any;
use function array_key_exists;
use function array_map;
use function array_values;
use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

use function sprintf;

/**
 * What a change to a lock moved under `reach.lockMoves: loaded` (ADR-0115): each package it moved, and the files of the
 * tests that loaded one of them.
 *
 * @internal
 */
final readonly class LockLoads
{
    private const string LOADED
        = '`%s` moved %s, and the tests that loaded them, or started a process that could have, run %d files.';

    /** @param Listed<string> $packages */
    private function __construct(private Listed $packages, private Paths $files)
    {
    }

    /**
     * What this lock's change moved, with the files the tests of this map that loaded it ran.
     *
     * @param Listed<string> $packages
     */
    public static function of(Listed $packages, CoverageMap $map): self
    {
        $tests = $map->loaded()->testsLoading($packages);
        $wanted = [];

        foreach ($map->tests() as $place => $test) {
            if ($tests->has($test)) {
                $wanted[$place] = true;
            }
        }

        $files = [];

        foreach ($map->placedLines() as $line) {
            if (array_any([...$line], static fn(int $place): bool => array_key_exists($place, $wanted))) {
                $files[$line->file()->value()] = $line->file();
            }
        }

        return new self($packages, Paths::of(...array_values($files)));
    }

    /** This reach, with the files of the tests that loaded what this lock moved. */
    public function reach(Reach $reach, Path $lock): Reach
    {
        $named = array_map(static fn(string $package): string => sprintf('`%s`', $package), [...$this->packages]);

        $why = sprintf(self::LOADED, $lock->value(), implode(', ', $named), count($this->files));

        return $reach->files($this->files, Reason::that($why));
    }
}
