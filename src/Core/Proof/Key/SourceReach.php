<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function ksort;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Php\NamedFiles;
use NightWorksIO\MutationGate\Core\Php\ReachedFiles;
use NightWorksIO\MutationGate\Core\Unit\Unit;

use function sort;

use const SORT_STRING;

use function sprintf;
use function strval;

/**
 * Which files outside the test directories a unit's key reads one by one,
 * rather than in the base every key reads (ADR-0007): the PHP files the
 * coverage map shows some test executed, whose runs the map observes.
 *
 * A unit's key reads, of those, the files its covering tests executed; every
 * file its own file, those files or the test files that can judge it name;
 * and every file a file named so names in what other files can read of it,
 * its declarations without their bodies, transitively. A body only acts
 * where it runs, and the map shows where it runs, so a file the unit's tests
 * never ran acts on them through its declarations alone: a constant, a
 * default, an attribute, a parent.
 *
 * Every other file outside the test directories is in the base, as no map
 * shows what runs it: a file that is no PHP, a PHP file no test executed, and
 * the config, autoload and Composer files among them, so a change to one
 * resets every key. Where what a unit reaches cannot be traced, its key
 * reads every file the map observes, failing closed: for a held unit, since
 * any test can join its group, and for a unit no test covers.
 */
final readonly class SourceReach
{
    /** The section of a key that holds the files a unit reaches. */
    private const string SECTION = 'reached';

    /**
     * @param array<string, true>                $observed each file the map shows some test executed, by its path
     * @param array<string, array<string, true>> $executed the files each test executed, by the test's id
     */
    private function __construct(
        private NamedFiles|NotGiven $names,
        private ReachedFiles|NotGiven $declared,
        private array $observed,
        private array $executed,
    ) {
    }

    /** No file read one by one: every file outside the test directories in the base, as where no graph is read. */
    public static function nothing(): self
    {
        return new self(NotGiven::value(), NotGiven::value(), [], []);
    }

    /**
     * The files each unit reaches through the project's name graph, and the graph of what its files declare, as
     * this map shows its tests ran them.
     */
    public static function of(NamedFiles $names, NamedFiles $declarations, CoverageMap $map): self
    {
        $observed = [];
        $executed = [];

        foreach ($map->files() as $file) {
            if (! $file->isPhp()) {
                continue;
            }

            $observed[$file->value()] = true;

            foreach ($map->testsCoveringFile($file) as $test) {
                $executed[$test->value()][$file->value()] = true;
            }
        }

        return new self($names, $declarations->reaches(), $observed, $executed);
    }

    /**
     * Of the files outside the test directories, those every key reads: each
     * the map does not observe, and each that defines the runner, whatever
     * the map shows.
     */
    public function guarded(Source $source): Fingerprints
    {
        $guarded = [];

        foreach ($source->files() as $file) {
            $defines = $source->definitions()->digestOf($file->path()) instanceof Digest;
            $guarded = $defines || ! $this->observes($file->path()) ? [...$guarded, $file] : $guarded;
        }

        return Fingerprints::of(...$guarded);
    }

    /**
     * The files a unit's key reads one by one, each by its digest among
     * these, framed as a key reads them, in byte order.
     *
     * @param Paths $tests the test files that can judge the unit, what of the test directories they read, and the
     *                     test files every key reads
     */
    public function readBy(Unit $unit, Paths $tests, CoverageMap $coverage, Fingerprints $files): string
    {
        return $this->framedReads($this->reachedBy($unit, $tests, $coverage), $files);
    }

    /**
     * Every file the map observes, each by its digest among these, framed as
     * {@see readBy()} frames a unit's: what a unit's key reads where what it
     * reaches cannot be traced, as where its tests read files.
     */
    public function readAll(Fingerprints $files): string
    {
        return $this->framedReads(array_map(strval(...), array_keys($this->observed)), $files);
    }

    /**
     * These files, each by its digest among those, framed in byte order.
     *
     * @param list<string> $paths
     */
    private function framedReads(array $paths, Fingerprints $files): string
    {
        sort($paths, SORT_STRING);
        $read = ContentKeys::framed(self::SECTION, sprintf('%d', count($paths)));

        foreach ($paths as $path) {
            $digest = $files->digestOf(Path::of($path));
            $read .= ContentKeys::framed($path, $digest instanceof Digest ? $digest->value() : Missing::DIGESTED);
        }

        return Digest::sha256Of($read)->value();
    }

    /** Whether the map shows some test executed a file, so a key reads it on its own. */
    private function observes(Path $path): bool
    {
        return array_key_exists($path->value(), $this->observed);
    }

    /**
     * The observed files a unit reaches, in byte order: the files its tests
     * executed and its own, and what those and its test files name, followed
     * through declarations.
     *
     * @return list<string>
     */
    private function reachedBy(Unit $unit, Paths $tests, CoverageMap $coverage): array
    {
        $executed = $this->executedBy($unit, $coverage);
        $from = [$unit->path()->value(), ...$executed];

        foreach ($tests as $test) {
            $from[] = $test->value();
        }

        $traced = ! $unit->isHeld() && count($coverage->lineSets($unit->path())) > 0;
        $reached = ! $traced || $this->names instanceof NotGiven || $this->declared instanceof NotGiven
            ? array_keys($this->observed)
            : $this->declared->from(...$this->names->named(...$from));
        $read = [];

        foreach ([...$reached, ...$executed, $unit->path()->value()] as $path) {
            $read += array_key_exists($path, $this->observed) ? [$path => strval($path)] : [];
        }

        ksort($read, SORT_STRING);

        return array_values($read);
    }

    /**
     * Every file the tests that cover a unit executed.
     *
     * @return list<string>
     */
    private function executedBy(Unit $unit, CoverageMap $coverage): array
    {
        $files = [];
        $seen = [];
        $lines = $coverage->lineSets($unit->path());

        foreach ($lines as $set) {
            if (array_key_exists($set->name(), $seen)) {
                continue;
            }

            $seen[$set->name()] = true;

            foreach ($lines->testsOf($set) as $test) {
                $files += array_key_exists($test->value(), $this->executed) ? $this->executed[$test->value()] : [];
            }
        }

        return array_map(strval(...), array_keys($files));
    }
}
