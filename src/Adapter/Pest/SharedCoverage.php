<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function count;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function is_string;
use function max;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Serialization\Serializer;

use function serialize;
use function sprintf;

/**
 * A coverage map another job handed over: only ever the gate's own, which is
 * data, and never a runner's map, which is PHP that reading runs. For Pest
 * patched to open on a canary group, the map is written again as
 * `--coverage-php` writes one, by this job, for this job's Pest to load.
 */
final readonly class SharedCoverage
{
    /** What a map says of how it was made, as php-code-coverage checks it: by the gate, from another job's map. */
    private const array BUILT = [
        'timestamp' => 'written by mutation-gate from the map another job handed over',
        'runtime' => ['name' => 'PHP', 'version' => PHP_VERSION, 'vendorUrl' => 'https://www.php.net/'],
        'phpCodeCoverage' => [
            'version' => 'as installed',
            'serializationFormat' => Serializer::SERIALIZATION_FORMAT,
            'driverInformation' => ['name' => ThisPackage::NAME, 'version' => 'map format 1'],
        ],
    ];

    /** How php-code-coverage writes a map it serialized, in the format of the version installed. */
    private const string MAP = <<<'PHP'
        <?php // phpunit/php-code-coverage serialization format %d
        return \unserialize(<<<'END_OF_COVERAGE_SERIALIZATION'
        %s
        END_OF_COVERAGE_SERIALIZATION
        );
        PHP;

    /** The map the gate wrote into a directory of the project, or why there is none to read. */
    public static function in(Project $project, Path $directory): CoverageMap|CannotJudge
    {
        $file = $project->absolute(CoverageMapFile::in($directory));
        $bytes = is_file($file) ? file_get_contents($file) : false;

        return is_string($bytes) ? CoverageMapFile::decode($bytes) : CoverageMapFile::missingAt($file);
    }

    /** How long the map's tests took, one after another. */
    public static function seconds(CoverageMap $map): float
    {
        $seconds = 0.0;

        foreach ($map->tests() as $test) {
            $seconds += self::secondsOf($map, $test->value());
        }

        return $seconds;
    }

    /**
     * Writes a map as `--coverage-php` writes one, with the project's root as
     * its base; a test the map did not time has no result, so nothing reads
     * it as timed.
     */
    public static function write(CoverageMap $map, Project $project, string $target): void
    {
        $places = [];
        $ids = [];
        $results = [];

        foreach ($map->tests() as $test) {
            $id = $test->value();

            if ($id === '') {
                continue;
            }

            $duration = $map->durationOf($test);
            $places[$id] = count($ids);
            $ids[] = $id;
            $results += $duration instanceof Seconds
                ? [$id => ['size' => 'unknown', 'status' => 'success', 'time' => $duration->seconds()]]
                : [];
        }

        $data = new ProcessedCodeCoverageData();
        $data->setTestIds($ids);
        $data->setLineCoverage(self::lines($map, $places));
        $coverage = [
            'buildInformation' => self::BUILT,
            'basePath' => $project->root(),
            'codeCoverage' => $data,
            'testResults' => $results,
        ];

        file_put_contents($target, sprintf(self::MAP, Serializer::SERIALIZATION_FORMAT, serialize($coverage)));
    }

    /**
     * Each covered line's tests, keyed by their places in the list of tests,
     * which leaves out a test with no name, as php-code-coverage cannot hold
     * one. A line no test ran is left out, as php-code-coverage leaves out
     * a line it does not know. The keys are the tests: a spread would number
     * them afresh.
     *
     * @param  array<string, int<0, max>>                                              $places
     * @return array<non-empty-string, array<int<1, max>, array<int<0, max>, int<1, max>>>>
     */
    private static function lines(CoverageMap $map, array $places): array
    {
        $lines = [];

        foreach ($map->lines() as $covered) {
            $hits = [];

            foreach ($covered as $test) {
                $hits += array_key_exists($test, $places) ? [$places[$test] => 1] : [];
            }

            if ([...$covered] !== []) {
                $lines[$covered->file()->value()][max(1, $covered->line())] = $hits;
            }
        }

        return $lines;
    }

    private static function secondsOf(CoverageMap $map, string $test): float
    {
        $duration = $map->durationOf(TestId::of($test));

        return $duration instanceof Seconds ? $duration->seconds() : 0.0;
    }
}
