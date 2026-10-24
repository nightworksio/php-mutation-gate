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

use const SORT_STRING;

use function sprintf;
use function strval;

/**
 * Which files outside the test directories a unit's key reads one by one,
 * rather than in the base every key reads (ADR-0007): the PHP files the
 * coverage map shows some test executed, whose runs the map observes. A
 * unit's key reads those of them its own file, the files its covering tests
 * executed, its judging test files and the test files every key reads name,
 * transitively through the project's name graph, and those of them its
 * covering tests executed. Every other file outside the test directories is
 * in the base, as no map shows what runs it: a file that is no PHP, a PHP
 * file no test executed, and the config, autoload and Composer files among
 * them, so a change to one resets every key. A held unit's key reads every
 * file the map observes, since any test can join its group.
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
        private ReachedFiles|NotGiven $reached,
        private array $observed,
        private array $executed,
    ) {
    }

    /** No file read one by one: every file outside the test directories in the base, as where no graph is read. */
    public static function nothing(): self
    {
        return new self(NotGiven::value(), [], []);
    }

    /** The files each unit reaches through this name graph, as this map shows its tests ran them. */
    public static function of(NamedFiles $names, CoverageMap $map): self
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

        return new self($names->reaches(), $observed, $executed);
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
     * @param Paths $always the test files every key reads
     */
    public function readBy(Unit $unit, Paths $judges, Paths $always, CoverageMap $coverage, Fingerprints $files): string
    {
        $reached = $this->reachedBy($unit, $judges, $always, $coverage);
        $read = ContentKeys::framed(self::SECTION, sprintf('%d', count($reached)));

        foreach ($reached as $path) {
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
     * The observed files a unit reaches, in byte order.
     *
     * @return list<string>
     */
    private function reachedBy(Unit $unit, Paths $judges, Paths $always, CoverageMap $coverage): array
    {
        $executed = $this->executedBy($unit, $coverage);
        $from = [$unit->path()->value(), ...$executed];

        foreach ([...$judges, ...$always] as $test) {
            $from[] = $test->value();
        }

        $reached = $unit->isHeld() || $this->reached instanceof NotGiven
            ? array_keys($this->observed)
            : $this->reached->from(...$from);
        $read = [];

        foreach ([...$reached, ...$executed] as $path) {
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
