<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_fill;
use function array_filter;
use function array_map;
use function array_sum;
use function array_unique;
use function array_values;
use function arsort;
use function asort;
use function count;
use function file_put_contents;
use function getenv;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use function key;
use function max;
use function min;

use NightWorksIO\MutationGate\Adapter\Pest\CoveringFiles;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\LoadedMap;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\PerTestVariables;
use NightWorksIO\MutationGate\Core\Runner\ToldSeconds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use Pest\Mutate\Plugins\Mutate;

use const PHP_BINARY;

use function reset;
use function sort;
use function sprintf;
use function str_starts_with;

use Symfony\Component\Process\Process;

/**
 * One run's passes of split streams (ADR-0117): each pass streams the test
 * files that cover its sites' mutants, cut into a stream for each process,
 * each part about as long as the next by the coverage map's times, every
 * stream its own Pest run of its part, forking at those sites.
 *
 * @internal
 */
final readonly class Streaming
{
    /** A pass's plan for one stream, in the run's directory. */
    private const string PLAN = '%s/plan-%d-%d.json';

    /** A pass's results of one stream, in the run's directory. */
    private const string RESULTS = '%s/results-%d-%d.jsonl';

    /**
     * @param array<string, string>       $paths     each schema's source file, by its file key
     * @param array<string, list<string>> $covering  each mutant's covering tests
     * @param list<string>                $arguments the arguments a mutant's own run is started with
     */
    public function __construct(
        private string $directory,
        private array $paths,
        private array $covering,
        private array $arguments,
        private int $processes,
        private int $children,
    ) {
    }

    /**
     * What one pass of streams found of these sites' mutants, beside what
     * earlier passes did.
     *
     * @param array<string, array{string, int, string, array<string, string>}> $sites each mutant's file key, switch id,
     *                                                                                 killer and variables
     */
    public function pass(int $pass, array $sites, StreamVerdicts $verdicts): StreamVerdicts
    {
        $tests = [];
        $planned = [];

        foreach ($sites as $mutant => [$key, $id, $killer, $variables]) {
            $tests = [...$tests, ...$this->covering[$mutant]];
            $planned[$key][$id] = [$mutant, $killer, $variables];
        }

        $times = self::times();
        $streams = [];

        foreach (self::parts(CoveringFiles::streamed(array_values(array_unique($tests))), $times, $this->processes) as $at => $files) {
            $results = sprintf(self::RESULTS, $this->directory, $pass, $at);
            $streams[] = [$this->started($pass, $at, $results, $planned, $times, $files), $results];
        }

        foreach ($streams as [$process, $results]) {
            $process->wait();
            $verdicts = $verdicts->reading($results);
        }

        return $verdicts;
    }

    /**
     * The test files, cut into at most as many parts as there are processes,
     * each file going to the part with least time so far, longest first; one
     * part of none where no file is known, which runs the whole suite.
     *
     * @param  array<string, array{files: list<string>, tests: list<string>}> $units
     * @param  array<string, float>                                           $times
     * @return list<list<string>>
     */
    public static function parts(array $units, array $times, int $processes): array
    {
        $count = min(max(1, $processes), max(1, count($units)));
        $seconds = [];

        foreach ($units as $file => $unit) {
            $seconds[$file] = array_sum(array_map(static fn(string $test): float => $times[$test] ?? 0.0, $unit['tests']));
        }

        arsort($seconds);
        $loads = array_fill(0, $count, 0.0);
        $parts = array_fill(0, $count, []);

        foreach ($seconds as $file => $time) {
            asort($loads);
            reset($loads);
            $least = (int) key($loads);
            $loads[$least] += $time;
            $parts[$least] = [...$parts[$least], ...$units[$file]['files']];
        }

        $cut = [];

        foreach ($parts as $files) {
            $unique = array_values(array_unique($files));
            sort($unique);
            $cut[] = $unique;
        }

        return array_values(array_filter($cut, static fn(array $files): bool => $files !== [] || $units === []));
    }

    /**
     * @param array<string, array<int, array{string, string, array<string, string>}>> $planned
     * @param array<string, float>                                                    $times
     * @param list<string>                                                            $files
     */
    private function started(int $pass, int $at, string $results, array $planned, array $times, array $files): Process
    {
        $plan = sprintf(self::PLAN, $this->directory, $pass, $at);
        $perTest = PerTestVariables::read(getenv(GateVariable::TestMultiple->value), getenv(GateVariable::TestFloor->value));
        $most = ToldSeconds::read(getenv(GateVariable::MutantCap->value));
        file_put_contents($plan, json_encode([
            'results' => $results,
            'sites' => $planned,
            'paths' => $this->paths,
            'times' => $times,
            'children' => $this->children,
            'multiple' => $perTest->isOn() ? $perTest->multiple() : 0,
            'floor' => $perTest->floor()->seconds(),
            'longest' => ($most instanceof Seconds ? $most : Triage::none()->most())->seconds(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $process = new Process(
            [PHP_BINARY, ...self::runArguments($this->arguments), ...$files],
            env: [
                GateVariable::SplitPlan->value => $plan,
                Mutate::ENV_MUTATION_TESTING => false,
                GateVariable::Results->value => false,
            ],
            timeout: null,
        );
        $process->disableOutput();
        $process->start();

        return $process;
    }

    /** @return array<string, float> each test's own time as the coverage map measured it */
    private static function times(): array
    {
        $times = [];

        foreach (LoadedMap::tests() as $test) {
            $seconds = LoadedMap::secondsOf($test);

            if (! $seconds instanceof NotGiven) {
                $times[$test] = $seconds;
            }
        }

        return $times;
    }

    /**
     * A stream's arguments: a mutant's own run's, without what Pest's own
     * process alone takes.
     *
     * @param  list<string> $arguments
     * @return list<string>
     */
    private static function runArguments(array $arguments): array
    {
        return array_values(array_filter(
            $arguments,
            static fn(string $argument): bool => ! str_starts_with($argument, '--coverage')
                && ! str_starts_with($argument, '--mutate')
                && ! str_starts_with($argument, '--parallel'),
        ));
    }
}
