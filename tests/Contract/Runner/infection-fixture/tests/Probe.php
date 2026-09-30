<?php

declare(strict_types=1);

namespace Tests;

/** What the contract suite asks a test's own process, where it asks. */
final class Probe
{
    /**
     * Appends the memory_limit this PHP process runs under to the file
     * LIBRARY_MEMORY names, and whether it is a mutant's own: Infection
     * serves a mutant through its include interceptor.
     */
    public static function memory(): void
    {
        $memory = getenv('LIBRARY_MEMORY');
        $where = class_exists('Infection\\StreamWrapper\\IncludeInterceptor', autoload: false) ? 'mutant' : 'suite';

        if ($memory !== false) {
            file_put_contents($memory, sprintf("%s %s\n", $where, ini_get('memory_limit')), FILE_APPEND | LOCK_EX);
        }
    }
}
