<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit\Warm;

use function array_key_exists;

use Closure;

use function count;
use function debug_backtrace;
use function get_included_files;

use NightWorksIO\MutationGate\Core\NotGiven;

use function realpath;
use function spl_autoload_register;
use function spl_autoload_unregister;
use function sprintf;

/**
 * What a warm worker's boot autoloaded, and from where: an autoloader asked
 * first for every class, which loads nothing itself and keeps the call stack
 * that asked, with how many files were loaded by then. The file loaded next
 * is the one the autoloader that answers loads for the class, so the line of
 * the bootstrap whose call loaded a file can be named (ADR-0023, decision 13).
 * A file loaded other than through an autoloader is named by the bootstrap
 * alone.
 */
final class BootSpy
{
    /** @var array<int, list<array{file?: string, line?: int}>> each stack that asked, by the files loaded then */
    private array $asked = [];

    /** The autoloader the spy is, registered as itself so it can be taken off again. */
    private readonly Closure $autoloader;

    private function __construct()
    {
        $this->autoloader = $this->asked(...);
    }

    /** A spy, asked first for every class from now on. */
    public static function watching(): self
    {
        $spy = new self();
        spl_autoload_register($spy->autoloader, prepend: true);

        return $spy;
    }

    /** No longer asked for any class, so nothing a child autoloads is kept. */
    public function stop(): void
    {
        spl_autoload_unregister($this->autoloader);
    }

    /** Keeps the call stack that asked for a class, and loads nothing. */
    public function asked(): void
    {
        $this->asked[count(get_included_files())] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    }

    /**
     * Where the boot loaded a file: the line of the bootstrap whose call had
     * it autoloaded, where the spy saw that; or nothing, where it did not.
     */
    public function lineOf(string $file, string $bootstrap): string|NotGiven
    {
        $at = NotGiven::value();
        $included = get_included_files();

        foreach ($this->asked as $loaded => $stack) {
            $next = array_key_exists($loaded, $included) ? realpath($included[$loaded]) : false;
            $at = $next === $file ? $this->frameIn($stack, $bootstrap) : $at;
        }

        return $at;
    }

    /** @param list<array{file?: string, line?: int}> $stack */
    private function frameIn(array $stack, string $bootstrap): string|NotGiven
    {
        foreach ($stack as $frame) {
            $file = array_key_exists('file', $frame) ? realpath($frame['file']) : false;

            if ($file === $bootstrap && array_key_exists('line', $frame)) {
                return sprintf('%s:%d', $bootstrap, $frame['line']);
            }
        }

        return NotGiven::value();
    }
}
