<?php

declare(strict_types=1);
use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\RunnerContracts;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// What every runner reports over the fixture library in fixture/: in
// src/Money.php a killed, a survived, an uncovered and a timed-out mutant, and
// in src/Held.php one held by the group holds:src/Held.php. Each runs against
// the fake (RunnerFake) and against every adapter whose runner is installed:
// the Pest adapter (Pest) once the runner contracts job has installed the
// library, the Infection adapter (Infection) once its job has installed
// infection-fixture/, the same code tested by PHPUnit, and the PHPUnit runner
// (PhpUnit) once its steps have installed phpunit-fixture/, whose library/
// holds the same code tested by PHPUnit. The Pest and Infection libraries also
// hold their runner's own ignore marker in marked/Marked.php; the PHPUnit
// runner has none. Every library holds beside/Ledger.php too, outside src/,
// which its phpunit.xml names as source and tests/LedgerSpec.php tests, and
// beside/Stock.php, which plugins/stock/tests/StockSpec.php tests, in a
// testsuite directory its phpunit.xml gives as plugins/*/tests. Each adapter
// is told the directories of its library's tests as the flows read them from
// that phpunit.xml. The runs are real, so each request runs once per library.

afterEach(function (): void {
    Scratch::sweep();
});

$makeMoney = static fn(): Closure => RunnerContracts::money(...);

it('times a run of no test that loads every test file through the mutant\'s wrapper and runs none, with Pest', function (): void {
    $marks = RunnerContracts::marks(static function (): void {
        Library::pest(Patching::off())->runner()->startUp(Path::of('src/Money.php'), Withheld::standard());
    });

    expect($marks)->toBe(['loaded' => true, 'wrapped' => true, 'ran' => false]);
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the library');

it('times a run of no test that loads no test file, through the mutant\'s wrapper, where a filtered run loads them all, with Infection', function (): void {
    $library = Library::infection(Seconds::of(10.0));
    $startUp = RunnerContracts::marks(static function () use ($library): void {
        $library->runner()->startUp(Path::of('src/Money.php'), Withheld::standard());
    });
    $filtered = RunnerContracts::marks(static function () use ($library): void {
        $library->runner()->coverage(CoverageRun::of(Filter::nothing(), Path::of('.mutation-gate/loaded')));
    });

    expect($startUp)->toBe(['loaded' => false, 'wrapped' => true, 'ran' => false])
        ->and($filtered)->toBe(['loaded' => true, 'wrapped' => false, 'ran' => false]);
})->skip(fn(): bool => ! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');

it('judges a held path by the tests its #[Holds] filter names, with Infection', function (): void {
    $library = Library::infection(Seconds::of(10.0));
    $request = MutationRequest::of(Paths::of(Path::of('src/Held.php')), Filter::matching('HeldSpec'))
        ->narrowedTo(Paths::of(Path::of('src/Held.php')), Narrowing::none()->toMutators($library->mutators('held')));
    $result = $library->mutate('held by filter', $request);

    expect($result instanceof MutationResult ? Library::records($result->mutants()) : $result)
        ->toBe($library->expected('held'));
})->skip(fn(): bool => ! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');

it('names the test that killed a mutant, as the coverage map names it, with Infection', function () use ($makeMoney): void {
    $money = $makeMoney();

    $library = Library::infection(Seconds::of(10.0));
    $result = $money($library);
    $killers = [];

    foreach ($result instanceof MutationResult ? $result->mutants() : Mutants::none() as $mutant) {
        $killers[$mutant->location()->start()->number()] = array_map(
            static fn(TestId $test): string => $test->value(),
            [...$mutant->killers()],
        );
    }

    expect($killers)->toBe([11 => ['Tests\\MoneySpec::addsTwoAmounts'], 16 => [], 21 => [], 27 => []]);
})->skip(fn(): bool => ! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');

it('judges a mutant on a method\'s signature by the map the planning job handed over as by its own coverage, with Infection', function (): void {
    $library = Library::infection(Seconds::of(10.0));
    $handedOver = Path::of('.mutation-gate/planned');
    $planned = $library->runner()->coverage(CoverageRun::of(WholeSuite::tests(), $handedOver));
    file_put_contents(
        Tree::at(sprintf('%s/%s', Library::INFECTION_DIRECTORY, CoverageMapFile::in($handedOver)->value())),
        CoverageMapFile::encode($planned instanceof CoverageMap ? $planned : CoverageMap::empty(), Unplaced::map()),
    );
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators(Mutators::named('PublicVisibility')));
    $own = $library->mutate('signatures', $request);
    $reused = $library->mutate('signatures reused', $request->reusingCoverage(Handed::maps($handedOver, $handedOver)));
    $killed = [];

    foreach ($reused instanceof MutationResult ? $reused->mutants() : Mutants::none() as $mutant) {
        $killed = $mutant->status() === MutantStatus::Killed
            ? [...$killed, $mutant->location()->start()->number()]
            : $killed;
    }

    expect($reused instanceof MutationResult ? Library::records($reused->mutants()) : $reused)
        ->toEqualCanonicalizing($own instanceof MutationResult ? Library::records($own->mutants()) : $own)
        ->and($killed)->toContain(9);
})->skip(fn(): bool => ! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');

it('names the test that killed a mutant, as the coverage map names it, with Pest', function () use ($makeMoney): void {
    $money = $makeMoney();

    $library = Library::pest(Patching::off());
    $result = $money($library);
    $killers = [];

    foreach ($result instanceof MutationResult ? $result->mutants() : Mutants::none() as $mutant) {
        $killers[$mutant->location()->start()->number()] = array_map(
            static fn(TestId $test): string => $test->value(),
            [...$mutant->killers()],
        );
    }

    expect($killers)->toBe([11 => ['P\\Tests\\MoneySpec::__pest_evaluable_it_adds_two_amounts'], 16 => [], 21 => [], 27 => []]);
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library');

it('gives a kill how far its run went, keyed by the test files it loaded, with patched Pest', function (): void {
    Patch::applyIn(Library::vendor());
    $library = Library::pest(Patching::on(Library::canary()));
    $result = $library->runner()->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('adds'))));
    $mutants = $result instanceof MutationResult ? iterator_to_array($result->mutants(), preserve_keys: false) : [];
    $prefix = $result instanceof MutationResult && $mutants !== [] ? $result->evidence()->of($mutants[0]->id())->prefix() : NotGiven::value();

    expect(array_map(static fn(Mutant $mutant): MutantStatus => $mutant->status(), $mutants))->toBe([MutantStatus::Killed])
        ->and($prefix instanceof Prefix && $prefix->position() >= 1)->toBeTrue()
        ->and($prefix instanceof Prefix ? $prefix->key() : '')->toMatch('/^[0-9a-f]{12}$/');
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library');

// A mutant that ends its process before any test fails is killed with no
// killer named, where the runner cannot tell which test it ended in. A
// runner that sees the process end says how: its code, whether a signal
// ended it, whether PHP recorded a fatal error in it, which `exit` is not, and
// the end of what it printed.
it('gives a kill that names no killer how its process ended, with patched Pest', function (): void {
    Patch::applyIn(Library::vendor());
    $library = Library::pest(Patching::on(Library::canary()));
    [$mutants, $evidence] = RunnerContracts::crashed(Library::DIRECTORY, ['src/Crash.php' => 'src/Crash.php', 'tests/CrashSpec.php' => 'tests/CrashSpec.php'], static fn(): MutationResult|CannotJudge => $library->runner()->mutate(RunnerContracts::crashRequest($library)));
    $ended = $mutants === [] ? NotGiven::value() : $evidence->of($mutants[0]->id())->ended();

    expect(array_map(static fn(Mutant $mutant): array => [$mutant->status(), count($mutant->killers())], $mutants))->toBe([[MutantStatus::Killed, 0]])
        ->and($ended instanceof Ended ? [is_int($ended->code()) && $ended->code() !== 0, $ended->signalled(), $ended->fatal()] : [])->toEqual([true, false, NotGiven::value()])
        ->and($mutants === [] ? null : $evidence->of($mutants[0]->id())->prefix())->toBeInstanceOf(NotGiven::class);
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library');

it('names the test a mutant ended the process in as its killer, and so gives no ending, with PHPUnit', function (Workers $workers): void {
    $library = Library::phpunit();
    [$mutants, $evidence] = RunnerContracts::crashed(Library::PHPUNIT_DIRECTORY, ['src/Crash.php' => 'src/Crash.php', 'tests/CrashSpec.php' => 'phpunit/CrashSpec.php'], static fn(): MutationResult|CannotJudge => $library->runner()->mutate(RunnerContracts::crashRequest($library)->across(Pool::of(ProcessCount::of(1), $workers))));
    $killers = $mutants === [] ? [] : array_map(static fn(TestId $test): string => $test->value(), [...$mutants[0]->killers()]);
    $prefix = $mutants === [] ? NotGiven::value() : $evidence->of($mutants[0]->id())->prefix();

    expect(array_map(static fn(Mutant $mutant): MutantStatus => $mutant->status(), $mutants))->toBe([MutantStatus::Killed])
        ->and($killers)->toBe(['Tests\\CrashSpec::settlesACodeOfNone'])
        ->and($prefix instanceof Prefix ? $prefix->position() : 0)->toBe(1)
        ->and($mutants === [] ? null : $evidence->of($mutants[0]->id())->ended())->toBeInstanceOf(NotGiven::class);
})->with([
    'in a fresh process' => [Workers::Fresh],
    'forked from a warm worker' => [Workers::Fork],
])->skip(fn(): bool => ! Library::isPhpUnitInstalled() || ! function_exists('pcntl_fork'), 'the runner contracts job installs the PHPUnit library, on a PHP that forks');

it('gives a kill that names no killer what its process printed, and no code or signal, which Infection does not log', function (): void {
    $library = Library::infection(Seconds::of(10.0));
    [$mutants, $evidence] = RunnerContracts::crashed(Library::INFECTION_DIRECTORY, ['src/Crash.php' => 'src/Crash.php', 'tests/CrashSpec.php' => 'phpunit/CrashSpec.php'], static fn(): MutationResult|CannotJudge => $library->runner()->mutate(RunnerContracts::crashRequest($library)));
    $ended = $mutants === [] ? NotGiven::value() : $evidence->of($mutants[0]->id())->ended();

    expect(array_map(static fn(Mutant $mutant): array => [$mutant->status(), count($mutant->killers())], $mutants))->toBe([[MutantStatus::Killed, 0]])
        ->and($ended instanceof Ended ? [$ended->code(), $ended->signalled(), $ended->fatal()] : [])->toEqual([NotGiven::value(), NotGiven::value(), false]);
})->skip(fn(): bool => ! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');

// The same, with PHPUnit, in a fresh process and forked from a warm worker:
// its run is stopped where no test of it starts or ends for its silence
// limit, as the results file the extension writes shows.
it('stops a mutant\'s run where no test of it starts or ends for its silence limit, short of its own limit, with PHPUnit', function (Workers $workers): void {
    $source = Tree::at(sprintf('%s/src/Stall.php', Library::PHPUNIT_DIRECTORY));
    $spec = Tree::at(sprintf('%s/tests/StallSpec.php', Library::PHPUNIT_DIRECTORY));
    copy(Tree::at('tests/Contract/Runner/stall/src/Stall.php'), $source);
    copy(Tree::at('tests/Contract/Runner/stall/phpunit/StallSpec.php'), $spec);
    $library = Library::phpunitWithin(LimitBounds::between(Seconds::of(1.0), Seconds::of(300.0)), 'phpunit under a floor of a second');

    try {
        $result = $library->runner()->mutate(MutationRequest::of(Paths::of(Path::of('src/Stall.php')), WholeSuite::tests())
            ->narrowedTo(Paths::of(Path::of('src/Stall.php')), Narrowing::none()->toMutators($library->mutators('drains')))
            ->across(Pool::of(ProcessCount::of(1), $workers)));
    } finally {
        unlink($source);
        unlink($spec);
    }

    $mutants = $result instanceof MutationResult ? iterator_to_array($result->mutants(), preserve_keys: false) : [];
    $seconds = static fn(Seconds|MemoryCap|Unmeasured $time): float => $time instanceof Seconds ? $time->seconds() : 0.0;

    expect(array_map(static fn(Mutant $mutant): MutantStatus => $mutant->status(), $mutants))->toBe([MutantStatus::TimedOut])
        ->and(array_map(static fn(Mutant $mutant): float => $seconds($mutant->limit()), $mutants))
        ->each->toBeGreaterThan(11.0)
        ->and(array_map(static fn(Mutant $mutant): float => $seconds($mutant->duration()), $mutants))
        ->each->toBeGreaterThan(7.0)->toBeLessThan(11.0)
        ->and(array_map(
            static fn(Mutant $mutant): string => $mutant->reason() instanceof Reason ? $mutant->reason()->text() : '',
            $mutants,
        ))
        ->each->toStartWith('No test finished for ');
})->with([
    'in a fresh process' => [Workers::Fresh],
    'forked from a warm worker' => [Workers::Fork],
])->skip(fn(): bool => ! Library::isPhpUnitInstalled() || ! function_exists('pcntl_fork'), 'the runner contracts job installs the PHPUnit library, on a PHP that forks');
