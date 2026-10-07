<?php

declare(strict_types=1);
use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Workers;
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

$files = RunnerContracts::files(...);

it('reports a mutant Infection skips at timeouts.most, allowed it, and judges it when run again at a higher most', function (): void {
    $library = Library::infection(Seconds::of(1.0));
    $request = MutationRequest::of(Paths::of(Path::of('src/Slow.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Slow.php')), Narrowing::none()->toMutators(Mutators::named('Minus')));
    $result = $library->mutate('slow', $request);
    $skipped = $result instanceof MutationResult ? $result->mutants() : Mutants::none();
    $limits = array_map(
        static fn(Mutant $mutant): float => $mutant->limit() instanceof Seconds ? $mutant->limit()->seconds() : 0.0,
        iterator_to_array($skipped, preserve_keys: false),
    );
    $again = $library->runner()->retry(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()), $skipped, Seconds::of(3.0));
    $statuses = static fn(Mutants $mutants): array => array_map(
        static fn(Mutant $mutant): string => $mutant->status()->value,
        iterator_to_array($mutants, preserve_keys: false),
    );

    expect($statuses($skipped))->toBe([MutantStatus::Skipped->value])
        ->and($limits)->toBe([1.0])
        ->and($again instanceof Mutants ? $statuses($again) : [])->toBe([MutantStatus::Killed->value]);
})->skip(! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');

it('allows a mutant Infection times out its own five seconds and five times its tests\' time, and runs it again only at timeouts.most', function (): void {
    $library = Library::infection(Seconds::of(10.0));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('drains')));
    $result = $library->mutate('drains', $request);
    $timedOut = $result instanceof MutationResult ? $result->mutants() : Mutants::none();
    $limits = array_map(
        static fn(Mutant $mutant): float => $mutant->limit() instanceof Seconds ? $mutant->limit()->seconds() : 0.0,
        iterator_to_array($timedOut, preserve_keys: false),
    );
    $again = $library->runner()->retry(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()), $timedOut, Seconds::of(20.0));

    expect(Library::records($timedOut))->toBe($library->expected('drains'))
        ->and($limits[0] ?? 0.0)->toBeGreaterThan(5.0)->toBeLessThan(6.0)
        ->and($again)->toEqual($timedOut);
})->skip(! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');

it('allows a mutant patched Pest times out the floor its quick tests fall under, and runs it again within a raised most', function (): void {
    Patch::applyIn(Library::vendor());
    $library = Library::pest(Patching::on(Library::canary()));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('drains')));
    $limits = static fn(Mutants $mutants): array => array_map(
        static fn(Mutant $mutant): float => $mutant->limit() instanceof Seconds ? $mutant->limit()->seconds() : 0.0,
        iterator_to_array($mutants, preserve_keys: false),
    );
    $result = $library->mutate('drains patched', $request);
    $timedOut = $result instanceof MutationResult ? $result->mutants() : Mutants::none();
    $again = $library->runner()->retry($request, $timedOut, Seconds::of(20.0));

    expect(Library::records($timedOut))->toBe($library->expected('drains'))
        ->and($limits($timedOut))->toBe([10.0])
        ->and($again instanceof Mutants ? Library::records($again) : [])->toBe($library->expected('drains'))
        ->and($again instanceof Mutants ? $limits($again) : [])->toBe([10.0]);
})->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');

// Three tests of most of a second each cover the stalled loop: the limit
// of all three is about 12 seconds, the silence limit of the slowest about
// 7. The mutant hangs in its first test, so no test finishes, and patched
// Pest stops its run at the silence limit, seconds before its own limit.
it('stops a mutant\'s run where no test of it finishes for its silence limit, short of its own limit, with patched Pest', function (): void {
    $source = Tree::at(sprintf('%s/src/Stall.php', Library::DIRECTORY));
    $spec = Tree::at(sprintf('%s/tests/StallSpec.php', Library::DIRECTORY));
    copy(Tree::at('tests/Contract/Runner/stall/src/Stall.php'), $source);
    copy(Tree::at('tests/Contract/Runner/stall/tests/StallSpec.php'), $spec);
    Patch::applyIn(Library::vendor());
    $library = Library::pestWithin(
        Patching::on(Library::canary()),
        LimitBounds::between(Seconds::of(1.0), Seconds::of(300.0)),
        'pest patched under a floor of a second',
    );

    try {
        $result = $library->runner()->mutate(MutationRequest::of(Paths::of(Path::of('src/Stall.php')), WholeSuite::tests())
            ->narrowedTo(Paths::of(Path::of('src/Stall.php')), Narrowing::none()->toMutators($library->mutators('drains'))));
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
})->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');

// The drained loop never ends under its mutant, and its one test takes no
// time, so its own limit is the floor of ten seconds. Its mutator is one
// timeouts.tighter lists, so its silence limit keeps above seven seconds
// only, and its run is stopped there, before its own limit.
it('stops a mutant\'s run at the lower floor timeouts.tighter gives its mutator, with patched Pest', function (): void {
    Patch::applyIn(Library::vendor());
    $library = Library::pestWithin(Patching::on(Library::canary()), RunnerContracts::tighterBounds('PostDecrementToPostIncrement'), 'pest patched, drains tighter');
    $result = $library->runner()->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('drains'))));

    expect(RunnerContracts::tighterSilenced($result))->toHaveCount(1)
        ->and(RunnerContracts::tighterSilenced($result)[0][0])->toBe(MutantStatus::TimedOut)
        ->and(RunnerContracts::tighterSilenced($result)[0][1])->toBeLessThan(9.5)
        ->and(RunnerContracts::tighterSilenced($result)[0][2])->toBe(Reason::silent(Seconds::of(7.0))->text());
})->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');

it('stops a mutant\'s run at the lower floor timeouts.tighter gives its mutator, with PHPUnit', function (Workers $workers): void {
    $library = Library::phpunitWithin(RunnerContracts::tighterBounds('PostDecrementToPostIncrement'), 'phpunit, drains tighter');
    $result = $library->runner()->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('drains')))
        ->across(Pool::of(ProcessCount::of(1), $workers)));

    expect(RunnerContracts::tighterSilenced($result))->toHaveCount(1)
        ->and(RunnerContracts::tighterSilenced($result)[0][0])->toBe(MutantStatus::TimedOut)
        ->and(RunnerContracts::tighterSilenced($result)[0][1])->toBeLessThan(9.5)
        ->and(RunnerContracts::tighterSilenced($result)[0][2])->toBe(Reason::silent(Seconds::of(7.0))->text());
})->with([
    'in a fresh process' => [Workers::Fresh],
    'forked from a warm worker' => [Workers::Fork],
])->skip(! Library::isPhpUnitInstalled() || ! function_exists('pcntl_fork'), 'the runner contracts steps install the PHPUnit library, on a PHP that forks');

// A floor of a millisecond is less than any covering test takes, so a
// limit the floor capped would stop each mutant's run before its tests end.
it('judges a mutant whose covering tests take longer than the floor, rather than stopping it at the floor, with PHPUnit', function (): void {
    $library = Library::phpunitWithin(LimitBounds::between(Seconds::of(0.001), Seconds::of(300.0)), 'phpunit under a floor of a millisecond');
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('adds')));
    $result = $library->mutate('adds', $request);

    expect($result instanceof MutationResult ? Library::records($result->mutants()) : [])->toBe($library->expected('adds'));
})->skip(! Library::isPhpUnitInstalled(), 'the PHPUnit runner contracts steps install its library');

it('judges a mutant whose covering tests take longer than the floor, rather than stopping it at the floor, with patched Pest', function (): void {
    Patch::applyIn(Library::vendor());
    $library = Library::pestWithin(
        Patching::on(Library::canary()),
        LimitBounds::between(Seconds::of(0.001), Seconds::of(300.0)),
        'pest patched under a floor of a millisecond',
    );
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('adds')));
    $result = $library->mutate('adds', $request);

    expect($result instanceof MutationResult ? Library::records($result->mutants()) : [])->toBe($library->expected('adds'));
})->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');

it('judges a mutant whose covering class takes longer than timeouts.seconds, skipping only at timeouts.most, with Infection', function (): void {
    $library = Library::infection(Seconds::of(3.0));
    $request = MutationRequest::of(Paths::of(Path::of('src/Slow.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Slow.php')), Narrowing::none()->toMutators(Mutators::named('Minus')));
    $result = $library->mutate('slow', $request);

    expect($result instanceof MutationResult ? array_map(
        static fn(Mutant $mutant): string => $mutant->status()->value,
        iterator_to_array($result->mutants(), preserve_keys: false),
    ) : [])->toBe([MutantStatus::Killed->value]);
})->skip(! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');

it('allows a mutant patched Infection times out the floor its quick tests fall under, and warns of nothing', function (): void {
    $library = Library::infectionWithin(Triage::standard()->bounds());
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('drains')));
    $result = RunnerContracts::withPatchedInfection(static fn(): MutationResult|CannotJudge => $library->runner()->mutate($request));
    $timedOut = $result instanceof MutationResult ? $result->mutants() : Mutants::none();

    expect(Library::records($timedOut))->toBe($library->expected('drains'))
        ->and(array_map(
            static fn(Mutant $mutant): float => $mutant->limit() instanceof Seconds ? $mutant->limit()->seconds() : 0.0,
            iterator_to_array($timedOut, preserve_keys: false),
        ))->toBe([10.0])
        ->and($result instanceof MutationResult ? [...$result->warnings()] : ['cannot judge'])->toBe([]);
})->skip(! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');

// Three test classes of a second each cover the stalled loop: the limit of
// all three is 14 seconds, the silence limit of the slowest 8. The mutant
// hangs in its first test, after PHPUnit printed its header, so patched
// Infection stops its run where it prints nothing for its silence limit.
it('stops a mutant\'s run where it prints nothing for its silence limit, short of its own limit, with patched Infection', function (): void {
    $files = ['src/Stall.php' => 'stall/src/Stall.php'];

    foreach (['First', 'Second', 'Third'] as $class) {
        $files[sprintf('tests/Stall%sSpec.php', $class)] = sprintf('stall/infection/Stall%sSpec.php', $class);
    }

    foreach ($files as $into => $from) {
        copy(Tree::at(sprintf('tests/Contract/Runner/%s', $from)), Tree::at(sprintf('%s/%s', Library::INFECTION_DIRECTORY, $into)));
    }

    $library = Library::infectionWithin(LimitBounds::between(Seconds::of(1.0), Seconds::of(300.0)));
    $request = MutationRequest::of(Paths::of(Path::of('src/Stall.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Stall.php')), Narrowing::none()->toMutators($library->mutators('drains')));
    try {
        $result = RunnerContracts::withPatchedInfection(static fn(): MutationResult|CannotJudge => $library->runner()->mutate($request));
    } finally {
        foreach (array_keys($files) as $into) {
            unlink(Tree::at(sprintf('%s/%s', Library::INFECTION_DIRECTORY, $into)));
        }
    }

    $mutants = $result instanceof MutationResult ? iterator_to_array($result->mutants(), preserve_keys: false) : [];

    expect(array_map(static fn(Mutant $mutant): MutantStatus => $mutant->status(), $mutants))->toBe([MutantStatus::TimedOut])
        ->and(array_map(static fn(Mutant $mutant): float => $mutant->limit() instanceof Seconds ? $mutant->limit()->seconds() : 0.0, $mutants))
        ->each->toBeGreaterThan(13.0)
        ->and(array_map(
            static fn(Mutant $mutant): string => $mutant->reason() instanceof Reason ? $mutant->reason()->text() : '',
            $mutants,
        ))
        ->each->toStartWith('No test finished for ');
})->skip(! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');

it('stops a mutant\'s run at the lower floor timeouts.tighter gives its mutator, with patched Infection', function (): void {
    $library = Library::infectionWithin(RunnerContracts::tighterBounds('Decrement'));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('drains')));
    $result = RunnerContracts::withPatchedInfection(static fn(): MutationResult|CannotJudge => $library->runner()->mutate($request));

    expect(RunnerContracts::tighterSilenced($result))->toHaveCount(1)
        ->and(RunnerContracts::tighterSilenced($result)[0][0])->toBe(MutantStatus::TimedOut)
        ->and(RunnerContracts::tighterSilenced($result)[0][2])->toBe(Reason::silent(Seconds::of(7.0))->text());
})->skip(! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');

// A test that stats a dangling link reads it alike through patched
// Infection's include-interceptor and without it, so the loop's < made <=,
// which looks twice and finds the link as before, survives, and its < made
// >=, which never looks, is killed.
it('stats a dangling link through patched Infection\'s interceptor as PHP does, so a test of one kills only a mutant that changes what it finds', function (): void {
    $files = ['src/Linked.php' => 'interceptor/Linked.php', 'tests/LinkedSpec.php' => 'interceptor/LinkedSpec.php'];

    foreach ($files as $into => $from) {
        copy(Tree::at(sprintf('tests/Contract/Runner/%s', $from)), Tree::at(sprintf('%s/%s', Library::INFECTION_DIRECTORY, $into)));
    }

    $library = Library::infectionWithin(Triage::standard()->bounds());
    $request = MutationRequest::of(Paths::of(Path::of('src/Linked.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Linked.php')), Narrowing::none()->toMutators(Mutators::named('LessThan', 'LessThanNegotiation')));

    try {
        $result = RunnerContracts::withPatchedInfection(static fn(): MutationResult|CannotJudge => $library->runner()->mutate($request));
    } finally {
        foreach (array_keys($files) as $into) {
            unlink(Tree::at(sprintf('%s/%s', Library::INFECTION_DIRECTORY, $into)));
        }
    }

    $loop = array_values(array_filter(
        $result instanceof MutationResult ? iterator_to_array($result->mutants(), preserve_keys: false) : [],
        static fn(Mutant $mutant): bool => $mutant->location()->start()->number() === 16,
    ));
    $judged = array_map(static fn(Mutant $mutant): array => [$mutant->mutation()->mutator(), $mutant->status()], $loop);
    usort($judged, static fn(array $one, array $other): int => $one[0] <=> $other[0]);

    expect($judged)->toBe([['LessThan', MutantStatus::Survived], ['LessThanNegotiation', MutantStatus::Killed]]);
})->skip(! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');

it('runs, patched, a mutant Infection skips unpatched at timeouts.most, and times it out at the most', function (): void {
    $library = Library::infectionWithin(LimitBounds::between(Seconds::of(1.0), Seconds::of(1.0)));
    $request = MutationRequest::of(Paths::of(Path::of('src/Slow.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Slow.php')), Narrowing::none()->toMutators(Mutators::named('Minus')));
    $result = RunnerContracts::withPatchedInfection(static fn(): MutationResult|CannotJudge => $library->runner()->mutate($request));

    expect($result instanceof MutationResult ? array_map(
        static fn(Mutant $mutant): array => [$mutant->status()->value, $mutant->limit()],
        iterator_to_array($result->mutants(), preserve_keys: false),
    ) : [$result])->toEqual([[MutantStatus::TimedOut->value, Seconds::of(1.0)]]);
})->skip(! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');
