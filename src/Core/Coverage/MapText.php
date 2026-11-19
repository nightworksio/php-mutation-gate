<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_intersect_key;
use function array_key_exists;

use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use stdClass;

/**
 * A coverage map's tables as its file writes them ({@see CoverageMapFile}):
 * every test with its seconds, each executable line of each file with its
 * tests by their place, and each file's executed methods. Read from a map
 * once, they write the map and any of its files alone.
 *
 * @internal the tables a map's file is written from
 *
 * @phpstan-import-type TestRecord from CoverageMapFile
 * @phpstan-import-type MethodRecord from CoverageMapFile
 */
final readonly class MapText
{
    /**
     * @param list<TestRecord>                     $tests
     * @param array<string, array<int, list<int>>> $files
     * @param array<string, list<MethodRecord>>    $methods
     * @param list<list<array{int, list<string>, bool}>> $loads each process's tests, each its place, its packages
     *                                                           and whether it started a process
     */
    private function __construct(
        private array $tests,
        private array $files,
        private array $methods,
        private array $loads,
    ) {
    }

    public static function of(CoverageMap $map): self
    {
        $tests = [];

        foreach ($map->tests() as $test) {
            $tests[] = self::test($test, $map);
        }

        $files = [];

        foreach ($map->lineTests()->placedLines() as $line) {
            $files[$line->file()->value()][$line->line()] = [...$line];
        }

        return new self($tests, $files, self::methodsOf($map), self::loadsOf($map));
    }

    /** These tables as the map `onlyFor()` these files writes them: every test, and these files' lines and methods. */
    public function onlyFor(Paths $files): self
    {
        $kept = [];

        foreach ($files as $file) {
            $kept[$file->value()] = null;
        }

        return new self(
            $this->tests,
            array_intersect_key($this->files, $kept),
            array_intersect_key($this->methods, $kept),
            $this->loads,
        );
    }

    /** The JSON text the map is written as, with where it was measured and each test file's entry key. */
    public function written(MeasuredAt|Unplaced $at, EntryKeys|NotGiven $keys): string
    {
        $keyed = $keys instanceof EntryKeys ? $keys->written() : [];

        return JsonText::compact([
            'format' => CoverageMapFile::FORMAT,
            ...($at instanceof MeasuredAt ? $at->written() : []),
            'tests' => $this->tests,
            'files' => $this->files === [] ? new stdClass() : $this->files,
            ...($this->methods === [] ? [] : [CoverageMapFile::METHODS => $this->methods]),
            ...($this->loads === [] ? [] : [CoverageMapFile::LOADS => $this->loads]),
            ...($keys instanceof EntryKeys ? [EntryKeys::FIELD => $keyed === [] ? new stdClass() : $keyed] : []),
        ]);
    }

    /** @return list<list<array{int, list<string>, bool}>> what each process's tests loaded, by their places */
    private static function loadsOf(CoverageMap $map): array
    {
        $places = [];

        foreach ($map->tests() as $place => $test) {
            $places[$test->value()] = $place;
        }

        $loads = [];

        foreach ($map->executed()->loaded()->processes() as $process) {
            $tests = [];

            foreach ($process->tests() as $test) {
                $id = $test->test()->value();

                if (array_key_exists($id, $places)) {
                    $tests[] = [$places[$id], [...$test->packages()], $test->startsProcesses()];
                }
            }

            $loads[] = $tests;
        }

        return $loads;
    }

    /** @return array<string, list<MethodRecord>> each file's executed methods, by path */
    private static function methodsOf(CoverageMap $map): array
    {
        $methods = [];

        foreach ($map->executed()->methods() as $file => $executed) {
            foreach ($executed as $method) {
                $methods[$file->value()][] = [
                    'name' => $method->name(),
                    'start' => $method->first()->number(),
                    'end' => $method->last()->number(),
                ];
            }
        }

        return $methods;
    }

    /** @return TestRecord */
    private static function test(TestId $test, CoverageMap $map): array
    {
        $seconds = $map->durations()->of($test);

        return $seconds instanceof Seconds
            ? ['id' => $test->value(), CoverageMapFile::SECONDS => $seconds->seconds()]
            : ['id' => $test->value()];
    }
}
