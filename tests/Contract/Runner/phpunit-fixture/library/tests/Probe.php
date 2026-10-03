<?php

declare(strict_types=1);

namespace Tests;

use function file_put_contents;
use function getenv;
use function ini_get;
use function ini_set;
use function sprintf;
use function str_repeat;

/** What the contract suite asks a test's own process, where it asks. */
final class Probe
{
    /**
     * Appends the memory_limit this PHP process runs under to the file
     * LIBRARY_MEMORY names, and whether it is a mutant's own: the gate tells
     * a mutant's PHPUnit the mutated file its override serves.
     */
    public static function memory(): void
    {
        $memory = getenv('LIBRARY_MEMORY');

        if ($memory !== false) {
            file_put_contents($memory, sprintf("%s %s\n", self::where(), ini_get('memory_limit')), FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * Where LIBRARY_HOG is set, has a mutant's own process hold memory until
     * PHP stops it: under the memory_limit LIBRARY_HOG names, or, where it is
     * `cap`, under the one the process runs under.
     */
    public static function hog(): void
    {
        $hog = getenv('LIBRARY_HOG');

        if ($hog === false || self::where() !== 'mutant') {
            return;
        }

        if ($hog !== 'cap') {
            ini_set('memory_limit', $hog);
        }

        $held = [];

        while (true) {
            $held[] = str_repeat('x', 1024);
        }
    }

    private static function where(): string
    {
        return getenv('MUTATION_GATE_MUTATED') === false ? 'suite' : 'mutant';
    }
}
