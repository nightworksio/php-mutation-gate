<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Codeception;

use Codeception\Event\FailEvent;
use Codeception\Event\SuiteEvent;
use Codeception\Event\TestEvent;
use Codeception\Events;
use Codeception\Extension as CodeceptionExtension;

use function file_put_contents;
use function getcwd;
use function getenv;
use function ini_get;
use function is_string;

use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Native\Candidate;
use NightWorksIO\MutationGate\Core\Native\Guard;
use NightWorksIO\MutationGate\Core\Native\ListedTests;
use NightWorksIO\MutationGate\Core\Native\Outcome;
use NightWorksIO\MutationGate\Core\Native\OwnFiles;
use NightWorksIO\MutationGate\Core\Native\Selected;
use NightWorksIO\MutationGate\Core\Native\Variable;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\CodeceptionSuiteSettings;
use NightWorksIO\MutationGate\Core\Runner\Opcache;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;

/**
 * The gate's Codeception extension, which the gate enables for each run it
 * starts and tells what to do in its settings (see Option, ADR-0027): it
 * skips every test the run does not select, and every test of a suite that
 * serves the application from another process, before anything of it runs;
 * records each test the run selects to the file `MUTATION_GATE_RESULTS`
 * names, where it names one; says in the file `MUTATION_GATE_GUARD` names
 * whether opcache could serve a cached original in place of the mutated
 * file; and measures the coverage, or lists the tests, where it is told to.
 */
final class Extension extends CodeceptionExtension
{
    /** Why a test the run does not select is skipped. */
    private const string SKIPPED = 'The gate runs only the tests it selects.';

    /** @var array<string, string> each event it listens for, and its method that hears it */
    public static array $events = [
        Events::SUITE_BEFORE => 'suite',
        Events::TEST_START => 'started',
        Events::TEST_SUCCESS => 'passed',
        Events::TEST_FAIL => 'failed',
        Events::TEST_ERROR => 'errored',
        Events::TEST_SKIPPED => 'neither',
        Events::TEST_INCOMPLETE => 'neither',
        Events::TEST_USELESS => 'neither',
        Events::TEST_END => 'ended',
        Events::RESULT_PRINT_AFTER => 'written',
    ];

    private readonly Selected $selected;

    private readonly string|NotGiven $results;

    private readonly Covering|NotGiven $covering;

    private readonly string|NotGiven $listing;

    /** @var list<Candidate> the tests a listing found, in the order Codeception runs them */
    private array $listed = [];

    /** Whether the suite running now serves the application from another process. */
    private bool $elsewhere = false;

    /** The test running now, where the run selects it. */
    private Candidate|NotGiven $running;

    /**
     * @param array<array-key, mixed> $config    its settings, as Codeception hands them over
     * @param array<array-key, mixed> $options   the run's options
     * @param CodeCoverage|NotGiven   $measuring what measures each test's coverage; one over the driver PHP has
     *                                           where none is given, as Codeception builds the extension
     */
    public function __construct(array $config, array $options, CodeCoverage|NotGiven $measuring = new NotGiven())
    {
        parent::__construct($config, $options);
        $told = Told::from($this->config);
        $results = getenv(Variable::Results->value);
        $this->selected = $told->selected();
        $this->results = is_string($results) && $results !== '' ? $results : NotGiven::value();
        $this->covering = $this->covering($told, $measuring);
        $this->listing = $told->listing();
        $this->running = NotGiven::value();
        self::noting(getenv(Variable::Guard->value), Opcache::ofCommandLine(ini_get(Opcache::CLI)));
    }

    /** Says in the guard file, where one is named, whether opcache could serve a cached original. */
    public static function noting(string|false $file, Opcache $opcache): void
    {
        if (is_string($file) && $file !== '') {
            file_put_contents($file, Guard::noting($opcache), FILE_APPEND | LOCK_EX);
        }
    }

    public function suite(SuiteEvent $event): void
    {
        $this->elsewhere = CodeceptionSuiteSettings::servesElsewhere(
            Node::decode(JsonText::compact($event->getSettings())),
        );
    }

    public function started(TestEvent $event): void
    {
        $test = $event->getTest();
        $candidate = Test::of($test);
        $listed = ! $this->listing instanceof NotGiven && ! $this->elsewhere;
        $this->listed = $listed ? [...$this->listed, $candidate] : $this->listed;

        if ($this->elsewhere || ! $this->selected->selects($candidate)) {
            $test->getMetadata()->setSkip(self::SKIPPED);

            return;
        }

        $this->running = $candidate;
        $this->recorded(Outcome::Started);

        if ($this->covering instanceof Covering) {
            $this->covering->started($candidate);
        }
    }

    public function passed(TestEvent $event): void
    {
        $this->recorded(Outcome::Passed);
    }

    public function failed(FailEvent $event): void
    {
        $this->recorded(Outcome::Failed);
    }

    public function errored(FailEvent $event): void
    {
        $this->recorded(Outcome::Errored);
    }

    public function neither(TestEvent $event): void
    {
        $this->recorded(Outcome::Neither);
    }

    public function ended(TestEvent $event): void
    {
        if ($this->running instanceof Candidate && $this->covering instanceof Covering) {
            $this->covering->ended($event->getTime());
        }

        $this->running = NotGiven::value();
    }

    public function written(): void
    {
        if ($this->covering instanceof Covering) {
            $this->covering->written();
        }

        if (is_string($this->listing)) {
            file_put_contents($this->listing, ListedTests::encoded(...$this->listed));
        }
    }

    /** Records how far the test running now got, where the run selects it and names a results file. */
    private function recorded(Outcome $outcome): void
    {
        if ($this->running instanceof Candidate && is_string($this->results)) {
            file_put_contents($this->results, $outcome->line($this->running->key()), FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * The coverage of each selected test, over the project's own files, where the run is told a file for it and
     * the vendor directory whose files it leaves out.
     */
    private function covering(Told $told, CodeCoverage|NotGiven $measuring): Covering|NotGiven
    {
        $map = $told->coverage();
        $vendor = $told->vendor();

        if ($map instanceof NotGiven || $vendor instanceof NotGiven) {
            return NotGiven::value();
        }

        $filter = new Filter();
        $own = OwnFiles::under((string) getcwd(), $vendor);

        return new Covering(
            $map,
            $measuring instanceof NotGiven ? new CodeCoverage(new Selector()->select($filter), $filter) : $measuring,
            $own,
        );
    }
}
