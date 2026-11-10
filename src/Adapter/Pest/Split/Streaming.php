<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_fill;
use function array_filter;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function file_put_contents;
use function getenv;
use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use function max;
use function min;

use NightWorksIO\MutationGate\Adapter\Pest\CoveringFiles;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\IdsFile;
use NightWorksIO\MutationGate\Adapter\Pest\LoadedMap;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\PerTestVariables;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;
use NightWorksIO\MutationGate\Core\Runner\ToldSeconds;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use Pest\Mutate\Plugins\Mutate;

use const PHP_BINARY;

use PHPUnit\Runner\Version;

use function sort;
use function sprintf;
use function str_starts_with;

use Symfony\Component\Process\Process;

use function version_compare;

/**
 * One run's passes of split streams (ADR-0117): each pass streams the test
 * files that cover its sites' mutants, cut into a stream for each process,
 * each part about as long as the next by the coverage map's times, every
 * stream its own Pest run of its part, forking at those sites; a first pass
 * may run only the sites' killers (ADR-0136).
 *
 * @internal
 */
final readonly class Streaming
{
    /** The lines one stream's tests executed, in the directory the run records them in (ADR-0135). */
    public const string RECORDED = '%s/recorded-%d-%d.jsonl';

    /** A pass's plan for one stream, in the run's directory. */
    private const string PLAN = '%s/plan-%d-%d.json';

    /** A pass's killers, named by their ids, in the run's directory. */
    private const string KILLERS = '%s/killers-%d.ids';

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
        private string $recording = '',
        private bool $bootOnce = false,
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

        foreach (array_keys($sites) as $mutant) {
            $tests = [...$tests, ...$this->covering[$mutant]];
        }

        return $this->streamed($pass, array_values(array_unique($tests)), self::plannedOf($sites), [], $verdicts);
    }

    /**
     * What one pass of streams of these test files found of these sites'
     * mutants, forking each at every test that reaches it, or first at its
     * likely killer: a run planned without a fresh map streams every test
     * file of its part, since its map cannot tell what covers a mutant
     * (ADR-0135).
     *
     * @param array<string, array{string, int, string, array<string, string>}> $sites
     * @param array<string, array{files: list<string>, tests: list<string>}>   $units
     */
    public function over(int $pass, array $sites, array $units, StreamVerdicts $verdicts): StreamVerdicts
    {
        return $this->streamedUnits($pass, $units, self::plannedOf($sites), [], $verdicts);
    }

    /**
     * What one pass of streams that run only these sites' killers, selected
     * by their ids, found of their mutants (ADR-0136); none where PHPUnit
     * cannot select them so.
     *
     * @param array<string, array{string, int, string, array<string, string>}> $sites each mutant's file key, switch id,
     *                                                                                 killer and variables
     */
    public function killersPass(int $pass, array $sites, StreamVerdicts $verdicts): StreamVerdicts|NotGiven
    {
        $killers = array_values(array_unique(array_map(static fn(array $site): string => $site[2], $sites)));
        $file = version_compare(Version::id(), PhpUnitOption::IDS_SINCE, '>=')
            ? IdsFile::written(
                TestIds::of(...array_map(TestId::of(...), $killers)),
                sprintf(self::KILLERS, $this->directory, $pass),
            )
            : NotGiven::value();

        return $file instanceof NotGiven
            ? $file
            : $this->streamed(
                $pass,
                $killers,
                self::plannedOf($sites),
                [PhpUnitOption::TestIdFilterFile->value, $file],
                $verdicts,
            );
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
        $parts = array_fill(0, min(max(1, $processes), max(1, count($units))), []);

        foreach (TestParts::assigned($units, $times, $processes) as $file => $at) {
            $parts[$at] = [...$parts[$at], ...$units[$file]['files']];
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
     * What one pass of streams of the files that hold these tests found,
     * each stream started with these arguments beside a mutant's own run's.
     *
     * @param list<string>                                                            $tests
     * @param array<string, array<int, array{string, string, array<string, string>}>> $planned
     * @param list<string>                                                            $selecting
     */
    private function streamed(
        int $pass,
        array $tests,
        array $planned,
        array $selecting,
        StreamVerdicts $verdicts,
    ): StreamVerdicts {
        return $this->streamedUnits($pass, CoveringFiles::streamed($tests), $planned, $selecting, $verdicts);
    }

    /**
     * What one pass of streams of these test files found, each stream started
     * with these arguments beside a mutant's own run's.
     *
     * @param array<string, array{files: list<string>, tests: list<string>}>          $units
     * @param array<string, array<int, array{string, string, array<string, string>}>> $planned
     * @param list<string>                                                            $selecting
     */
    private function streamedUnits(
        int $pass,
        array $units,
        array $planned,
        array $selecting,
        StreamVerdicts $verdicts,
    ): StreamVerdicts {
        $times = LoadedMap::timed();
        $streams = [];

        foreach (self::parts($units, $times, $this->processes) as $at => $files) {
            $results = sprintf(self::RESULTS, $this->directory, $pass, $at);
            $streams[] = [$this->started($pass, $at, $results, $planned, $times, [...$selecting, ...$files]), $results];
        }

        foreach ($streams as [$process, $results]) {
            $process->wait();
            $verdicts = $verdicts->reading($results);
        }

        return $verdicts;
    }

    /**
     * One stream of a pass, started on its selecting arguments, then its test
     * files; where it records, its file of lines starts empty, so a stream
     * that never ended its tests leaves a file that says so (ADR-0135).
     *
     * @param array<string, array<int, array{string, string, array<string, string>}>> $planned
     * @param array<string, float>                                                    $times
     * @param list<string>                                                            $files
     */
    private function started(int $pass, int $at, string $results, array $planned, array $times, array $files): Process
    {
        $plan = sprintf(self::PLAN, $this->directory, $pass, $at);
        $perTest = PerTestVariables::read(
            getenv(GateVariable::TestMultiple->value),
            getenv(GateVariable::TestFloor->value),
        );
        $most = ToldSeconds::read(getenv(GateVariable::MutantCap->value));
        $fields = [
            'results' => $results,
            'sites' => $planned,
            'paths' => $this->paths,
            'times' => $times,
            'children' => $this->children,
            'multiple' => $perTest->isOn() ? $perTest->multiple() : 0,
            'floor' => $perTest->floor()->seconds(),
            'longest' => ($most instanceof Seconds ? $most : Triage::none()->most())->seconds(),
            'stepFloor' => (int) getenv(GateVariable::StepFloor->value),
            'recording' => $this->recording === '' ? '' : sprintf(self::RECORDED, $this->recording, $pass, $at),
            'bootOnce' => $this->bootOnce && $pass === 1,
        ];
        file_put_contents($plan, json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        if ($fields['recording'] !== '') {
            file_put_contents($fields['recording'], '');
        }
        $process = new Process(
            [PHP_BINARY, ...self::counting(), ...$this->runArguments($this->arguments), ...$files],
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

    /**
     * What loads `outbreak.so` into a stream, where the gate asks it to watch
     * states or touches (ADR-0130); nothing otherwise.
     *
     * @return list<string>
     */
    private static function counting(): array
    {
        $extension = getenv(GateVariable::StreamExtension->value);

        return is_string($extension) && $extension !== '' ? ['-d', sprintf('extension=%s', $extension)] : [];
    }

    /**
     * A stream's arguments: a mutant's own run's, without what Pest's own
     * process alone takes.
     *
     * @param  list<string> $arguments
     * @return list<string>
     */
    private function runArguments(array $arguments): array
    {
        return array_values(array_filter(
            $arguments,
            static fn(string $argument): bool => ! str_starts_with($argument, '--coverage')
                && ! str_starts_with($argument, '--mutate')
                && ! str_starts_with($argument, '--parallel'),
        ));
    }

    /**
     * Each site's mutant, killer and variables, by its file key and switch id.
     *
     * @param  array<string, array{string, int, string, array<string, string>}>        $sites
     * @return array<string, array<int, array{string, string, array<string, string>}>>
     */
    private static function plannedOf(array $sites): array
    {
        $planned = [];

        foreach ($sites as $mutant => [$key, $id, $killer, $variables]) {
            $planned[$key][$id] = [$mutant, $killer, $variables];
        }

        return $planned;
    }
}
