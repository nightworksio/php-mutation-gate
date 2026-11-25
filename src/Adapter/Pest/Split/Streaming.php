<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_count_values;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function count;

use Fidry\CpuCoreCounter\CpuCoreCounter;

use function file_put_contents;
use function getenv;
use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use function max;

use NightWorksIO\MutationGate\Adapter\Pest\CoveringFiles;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\IdsFile;
use NightWorksIO\MutationGate\Adapter\Pest\LoadedMap;
use NightWorksIO\MutationGate\Adapter\Pest\PcovTests;
use NightWorksIO\MutationGate\Core\Config\StreamChildren;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\PerTestVariables;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;
use NightWorksIO\MutationGate\Core\Runner\RunningProcesses;
use NightWorksIO\MutationGate\Core\Runner\ToldSeconds;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use Pest\Mutate\Plugins\Mutate;

use const PHP_BINARY;

use function sprintf;
use function str_starts_with;
use function strval;

use Symfony\Component\Process\Process;

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

    /** The covering tests one stream of a pass runs, named by their ids, in the run's directory (ADR-0181). */
    private const string SELECTED = '%s/selected-%d-%d.ids';

    /** A pass's results of one stream, in the run's directory. */
    private const string RESULTS = '%s/results-%d-%d.jsonl';

    /**
     * @param array<string, string> $paths     each schema's source file, by its file key
     * @param list<string>          $arguments the arguments a mutant's own run is started with
     */
    public function __construct(
        private string $directory,
        private array $paths,
        private array $arguments,
        private int $processes,
        private int|StreamChildren $children,
        private RunningProcesses $running,
        private string $recording = '',
        private bool $bootOnce = false,
        private bool $reconnect = false,
        private bool $coveringOnly = false,
        private StreamPace $pace = new StreamPace(),
        private PcovTests|NotGiven $pcov = new NotGiven(),
        private StreamSlowdown $slowdown = new StreamSlowdown(),
    ) {
    }

    /**
     * What one pass of streams of the files that hold these covering tests
     * of these sites' mutants found of them, beside what earlier passes did:
     * each stream runs only those tests of its files, selected by their ids,
     * where the run says so, records no lines for a kept map, and can name
     * every one so, and every test of its files otherwise (ADR-0181).
     *
     * @param array<string, array{string, int, string, array<string, string>}> $sites    each mutant's file key, switch
     *                                                                                    id, killer and variables
     * @param array<string, list<string>>                                       $covering the covering tests of each
     *                                                                                    mutant the pass streams
     */
    public function pass(int $pass, array $sites, array $covering, StreamVerdicts $verdicts): StreamVerdicts
    {
        return $this->streamed(
            $pass,
            PartCosts::forksOf(array_map(strval(...), array_keys($sites)), $covering),
            $this->plannedOf($sites),
            [],
            $verdicts,
            $this->coveringOnly && $this->recording === '',
        );
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
        return $this->streamedUnits($pass, $units, [], $this->plannedOf($sites), [], $verdicts);
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
        $forks = array_count_values(array_map(static fn(array $site): string => $site[2], $sites));
        $file = IdsFile::written(
            TestIds::of(...array_map(TestId::of(...), array_map(strval(...), array_keys($forks)))),
            sprintf(self::KILLERS, $this->directory, $pass),
        );

        return $file instanceof NotGiven
            ? $file
            : $this->streamed(
                $pass,
                $forks,
                $this->plannedOf($sites),
                [PhpUnitOption::TestIdFilterFile->value, $file],
                $verdicts,
            );
    }

    /**
     * The test files, cut into at most as many parts as there are processes,
     * each file going to the part with least time so far, longest first, each
     * part beside the tests of its files the units name, its files in the
     * order it runs them: the fastest first, so a mutant a fast test kills is
     * killed before the slow ones run and its run is short to replay, a file
     * the map did not time after every one it did, then by path; one part of
     * none where no file is known, which runs the whole suite.
     *
     * @param  array<string, array{files: list<string>, tests: list<string>}> $units
     * @param  array<string, float>                                           $times
     * @return list<array{files: list<string>, tests: list<string>}>
     */
    public static function cut(array $units, array $times, int $processes): array
    {
        $parts = [];

        foreach (TestParts::assigned($units, $times, $processes) as $file => $at) {
            $parts[$at][] = $file;
        }

        return array_map(
            static fn(array $held): array => TestParts::fastestFirst($held, $units, $times),
            $units === [] ? [[]] : array_values($parts),
        );
    }

    /**
     * What one pass of streams of the files that hold these tests found,
     * each stream started with these arguments beside a mutant's own run's,
     * and running only those tests of its files where it selects them;
     * nothing more where no loaded file holds any of them, which leaves
     * their mutants to their own runs (ADR-0171).
     *
     * @param array<string, int>                                                      $forks    how many mutants the
     *                                                                                          pass forks at each test
     * @param array<string, array<int, array{string, string, array<string, string>}>> $planned
     * @param list<string>                                                            $selecting
     */
    private function streamed(
        int $pass,
        array $forks,
        array $planned,
        array $selecting,
        StreamVerdicts $verdicts,
        bool $selects = false,
    ): StreamVerdicts {
        $units = CoveringFiles::streamed(array_map(strval(...), array_keys($forks)));

        return $units === []
            ? $verdicts
            : $this->streamedUnits($pass, $units, $forks, $planned, $selecting, $verdicts, $selects);
    }

    /**
     * What one pass of streams of these test files found, each stream started
     * with these arguments beside a mutant's own run's, and, where it selects
     * them, the ids of its part's tests the units name: the files cut into
     * `splitStream.partsPerProcess` parts for each process by their tests'
     * cost, a test's map time once for its parent and once more for each
     * mutant the pass forks at it, the costliest part started first and the
     * next as one ends, no more running at once than there are processes
     * (ADR-0188).
     *
     * @param array<string, array{files: list<string>, tests: list<string>}>          $units
     * @param array<string, int>                                                      $forks
     * @param array<string, array<int, array{string, string, array<string, string>}>> $planned
     * @param list<string>                                                            $selecting
     */
    private function streamedUnits(
        int $pass,
        array $units,
        array $forks,
        array $planned,
        array $selecting,
        StreamVerdicts $verdicts,
        bool $selects = false,
    ): StreamVerdicts {
        $times = LoadedMap::timed();
        $costs = PartCosts::of($times, $forks);
        $cores = new CpuCoreCounter()->getCountWithFallback(1);
        $depth = max(1, $this->pace->partsPerProcess);
        [$reading, $others] = $this->pcov instanceof PcovTests ? $this->pcov->split($units) : [[], $units];
        $readers = PcovTests::processesFor($reading, $others, $times, $this->processes);
        $keeping = $reading === [] ? [] : self::cut($reading, $costs->byTest(), $readers * $depth);
        $parts = [
            ...$keeping,
            ...$others === [] && $reading !== []
                ? []
                : self::cut($others, $costs->byTest(), max(1, $this->processes - $readers) * $depth),
        ];
        $kept = count($keeping);
        $queued = [];

        foreach ($costs->costliestFirst($parts) as $at) {
            $part = $parts[$at];
            $start = new StreamStart(
                $pass,
                $at,
                count($parts),
                $planned,
                $times,
                $cores,
                $selecting,
                $at < $kept,
                $this->slowdown->start(),
            );
            $queued[] = fn(): WatchedStream => $this->watched($start, $part['tests'], $part['files'], $selects);
        }

        $watch = new StreamWatch($this->directory, $this->slowdown, $this->running);

        foreach ($watch->ended($queued, max(1, $this->processes)) as $results) {
            $verdicts = $verdicts->reading($results);
        }

        $this->slowdown->passed();

        return $verdicts;
    }

    /**
     * One stream of a pass, started on these test files: of these tests
     * alone, named in a file of their ids, where it is chosen so, and on
     * the pass's selecting arguments otherwise. A stream stopped in a test
     * is started again in its place, of its tests but that one, at the
     * place one round of streams on (ADR-0182), its parent's deadline
     * starting after the slowdown the stopped one reached (ADR-0184).
     *
     * @param list<string> $tests
     * @param list<string> $files
     */
    private function watched(StreamStart $start, array $tests, array $files, bool $chosen): WatchedStream
    {
        $results = sprintf(self::RESULTS, $this->directory, $start->pass, $start->at);
        $arguments = $chosen
            ? [...$this->selected($start->pass, $start->at, $tests), ...$files]
            : [...$start->selecting, ...$files];

        return new WatchedStream(
            $this->started($start, $results, $arguments),
            $results,
            $chosen || $start->selecting !== [] ? $tests : [],
            fn(array $left, float $slowdown): WatchedStream => $this->watched(
                $start->next($this->pace->after($slowdown)),
                $left,
                $files,
                chosen: true,
            ),
        );
    }

    /**
     * What selects exactly these tests in one stream of a pass, by a file of
     * their ids; nothing where they cannot be named so, which runs every test
     * of the stream's files (ADR-0181).
     *
     * @param  list<string> $tests
     * @return list<string>
     */
    private function selected(int $pass, int $at, array $tests): array
    {
        $file = IdsFile::written(
            TestIds::of(...array_map(TestId::of(...), $tests)),
            sprintf(self::SELECTED, $this->directory, $pass, $at),
        );

        return $file instanceof NotGiven ? [] : [PhpUnitOption::TestIdFilterFile->value, $file];
    }

    /**
     * Whether a pass's streams boot the application once and fork each test
     * from it: the first's, where the run says so. Such a stream records no
     * lines, which would miss what the boot executed.
     */
    private function bootsOnceIn(int $pass): bool
    {
        return $this->bootOnce && $pass === 1;
    }

    /**
     * One stream of a pass, started on its selecting arguments, then its test
     * files; where it records, its file of lines starts empty, so a stream
     * that never ended its tests leaves a file that says so (ADR-0135).
     *
     * @param list<string> $files
     */
    private function started(StreamStart $start, string $results, array $files): Process
    {
        $pass = $start->pass;
        $at = $start->at;
        $plan = sprintf(self::PLAN, $this->directory, $pass, $at);
        $perTest = PerTestVariables::read(
            getenv(GateVariable::TestMultiple->value),
            getenv(GateVariable::TestFloor->value),
        );
        $most = ToldSeconds::read(getenv(GateVariable::MutantCap->value));
        $fields = [
            'results' => $results,
            'sites' => $start->planned,
            'paths' => $this->paths,
            'times' => $start->times,
            'children' => $this->children instanceof StreamChildren ? $this->children->value : $this->children,
            'cores' => $start->cores,
            'live' => $this->directory,
            'multiple' => $perTest->isOn() ? $perTest->multiple() : 0,
            'floor' => $perTest->floor()->seconds(),
            'longest' => ($most instanceof Seconds ? $most : Triage::none()->limits()->most())->seconds(),
            'stepFloor' => (int) getenv(GateVariable::StepFloor->value),
            'recording' => $this->recording === '' || $this->bootsOnceIn($pass)
                ? ''
                : sprintf(self::RECORDED, $this->recording, $pass, $at),
            'bootOnce' => $this->bootsOnceIn($pass),
            'reconnect' => $this->reconnect,
            'deadline' => $this->pace->on,
            'slowdown' => $start->slowdown,
        ];
        file_put_contents($plan, json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        if ($fields['recording'] !== '') {
            file_put_contents($fields['recording'], '');
        }
        $process = new Process(
            [
                PHP_BINARY,
                ...$this->counting(),
                ...$this->reach($fields['recording']),
                ...$this->runArguments($this->arguments),
                ...$files,
            ],
            env: [
                GateVariable::SplitPlan->value => $plan,
                Mutate::ENV_MUTATION_TESTING => false,
                GateVariable::Results->value => false,
                ...$start->keepsPcov ? PcovTests::kept() : PcovTests::off(),
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
    private function counting(): array
    {
        $extension = getenv(GateVariable::StreamExtension->value);

        return is_string($extension) && $extension !== '' ? ['-d', sprintf('extension=%s', $extension)] : [];
    }

    /**
     * What has pcov collect from every tree of the project, and from the
     * schemata the stream serves, where the stream records its tests' lines;
     * nothing otherwise.
     *
     * @return list<string>
     */
    private function reach(string $recording): array
    {
        return $recording === '' ? [] : StreamReach::options();
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
    private function plannedOf(array $sites): array
    {
        $planned = [];

        foreach (array_map(strval(...), array_keys($sites)) as $mutant) {
            [$key, $id, $killer, $variables] = $sites[$mutant];
            $planned[$key][$id] = [$mutant, $killer, $variables];
        }

        return $planned;
    }
}
