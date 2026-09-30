<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_keys;
use function array_map;
use function array_values;
use function explode;
use function file_get_contents;
use function is_array;
use function json_decode;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use Pest\Mutate\Mutation;
use Pest\Mutate\MutationSuite;
use Pest\Mutate\MutationTest;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;
use Pest\Mutate\Repositories\TelemetryRepository;
use Pest\Mutate\Support\MutationTestResult;

use function rtrim;
use function sprintf;

use Symfony\Component\Finder\SplFileInfo;

/** pest-plugin-mutate's own objects, as a Pest run holds them, for the plugin's tests. */
final readonly class Mutations
{
    public const string PLUS = PlusToMinus::class;

    /** A suite of one mutant per result given, on lines 11, 12 and so on of a file, each with that result. */
    public static function suite(string $file, MutationTestResult ...$results): MutationSuite
    {
        $suite = new MutationSuite();

        $ordered = array_values($results);

        foreach (array_keys($ordered) as $at) {
            $suite->repository->add(self::mutation($file, sprintf('id-%d', $at + 1), 11 + $at));
        }

        foreach ($suite->repository->all() as $collection) {
            foreach ($collection->tests() as $at => $test) {
                $test->updateResult($ordered[$at]);
            }
        }

        return $suite;
    }

    public static function mutation(string $file, string $id, int $line): Mutation
    {
        return new Mutation(
            new SplFileInfo($file, '', ''),
            $id,
            self::PLUS,
            $line,
            $line + 1,
            "  <fg=red>-        return \$a + \$b;</>\n  <fg=green>+        return \$a - \$b;</>\n",
            '/nowhere/mutated',
        );
    }

    public static function test(string $file, string $id, MutationTestResult $result): MutationTest
    {
        $test = new MutationTest(self::mutation($file, $id, 11));
        $test->updateResult($result);

        return $test;
    }

    /** A recorder writing to a results file, with Pest's coverage map at a path and an opening run of 1.5 seconds. */
    public static function recorder(string $results, string $coverage): Recorder
    {
        $telemetry = new TelemetryRepository();
        $telemetry->initialTestSuiteDuration(1.5);

        return new Recorder($results, $coverage, $telemetry);
    }

    /**
     * What a results file holds, one decoded record per line.
     *
     * @return list<mixed>
     */
    public static function recorded(string $results): array
    {
        $lines = explode("\n", rtrim((string) file_get_contents($results), "\n"));

        return array_map(static function (string $line): mixed {
            $record = json_decode($line, associative: true);

            return is_array($record) ? $record : $line;
        }, $lines);
    }
}
