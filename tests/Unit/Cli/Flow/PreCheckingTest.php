<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\AnalyserWarmUp;
use NightWorksIO\MutationGate\Cli\Flow\PreChecking;
use NightWorksIO\MutationGate\Cli\Flow\Stopwatch;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistory;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\Analysis\CheckTime;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\NoAnalyser;
use NightWorksIO\MutationGate\Core\Analysis\OutOfScope;
use NightWorksIO\MutationGate\Core\Analysis\PreCheck;
use NightWorksIO\MutationGate\Core\Analysis\PreCheckable;
use NightWorksIO\MutationGate\Core\Analysis\PreCheckables;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\Analysis\RejectionRate;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\StaticCheckerFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\RecordingChecker;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\TickingClock;
use NightWorksIO\MutationGate\Tests\Support\Tree;

afterEach(function (): void {
    Scratch::sweep();
});

/** The analyser the pre-checks run: named fake, at 1.0.0. */
function preIdentity(): AnalyserIdentity
{
    return AnalyserIdentity::of('fake', '1.0.0', Digest::sha256Of('{}'));
}

/** What the analyser finds in the originals: one error they already have. */
function preOriginals(): Findings
{
    return Findings::of(Finding::error(Path::of('src/Money.php'), 'known', 'The originals already have this.'));
}

/**
 * Two mutants of the fixture's Money, of different mutators.
 *
 * @return list<Mutant>
 */
function preOffered(): array
{
    $mutants = [...Mutants::of(...Flows::mutantsOf('src/Money.php'))];
    $byMutator = [];

    foreach ($mutants as $mutant) {
        $byMutator[$mutant->mutator()] ??= $mutant;
    }

    return array_slice(array_values($byMutator), 0, 2);
}

/** A mutant offered with its text as written, behind tests that take this long. */
function preOffer(Mutant $mutant, float $tests = 10.0): PreCheckable
{
    return PreCheckable::of(
        $mutant,
        Checkable::inPlace(Contents::of(sprintf('<?php // %s', $mutant->id()->value()))),
        Seconds::of($tests),
    );
}

/** The pre-checks, in a project, by this analyser, with what the ledgers learned. */
function preChecking(string $project, RecordingChecker|NoAnalyser $checker, AnalyserHistory $known, Deadline|Unlimited $deadline = new Unlimited()): PreChecking
{
    $ports = $checker instanceof RecordingChecker ? [ScriptedRunner::fixture(), $checker] : [ScriptedRunner::fixture()];
    $clock = new TickingClock('2026-01-01T00:00:00Z', 1);

    return new PreChecking(
        Flows::adapters($project, [], ...$ports),
        $clock,
        $deadline,
        Seconds::of(60.0),
        $known,
        new AnalyserWarmUp(),
        new Stopwatch($clock, $clock->now()),
        PreCheck::standard(),
    );
}

/** @param array<string, Findings|OutOfScope|CannotJudge> $answers what the analyser answers of each mutant, by where it reads it */
function preCheckedBy(string $project, array $answers): RecordingChecker
{
    return new RecordingChecker(new StaticCheckerFake(preIdentity(), preOriginals(), $answers), $project);
}

it('rejects a mutant whose check finds an error its original does not have, passes one that finds none, and learns both', function (): void {
    $project = Scratch::directory();
    [$first, $second] = preOffered();
    $new = Finding::error(Path::of('src/Money.php'), 'return.type', 'Method Money::add() should return int.');
    $checker = preCheckedBy($project, [
        Workspace::checkedMutant($first->id())->value() => Findings::of(...preOriginals(), ...Findings::of($new)),
        Workspace::checkedMutant($second->id())->value() => preOriginals(),
    ]);
    $checking = preChecking($project, $checker, AnalyserHistory::of('fake'));

    $rejections = $checking->rejected(PreCheckables::of(preOffer($first), preOffer($second)), ProcessCount::of(2));
    $learned = $checking->checks()->histories()->of(preIdentity());
    $rate = static fn(Mutant $mutant): array => [
        $learned->rateOf($mutant->mutation())->checks(),
        $learned->rateOf($mutant->mutation())->rejections(),
    ];

    expect(count($rejections))->toBe(1)
        ->and($rejections->of($first->id()))->toEqual(Rejection::by('fake', $new))
        ->and($rate($first))->toBe([1, 1])
        ->and($rate($second))->toBe([1, 0])
        ->and($learned->time()->checks())->toBe(2)
        ->and(array_map(static fn(array $check): string => $check[2], $checker->asked()))->toBe([
            sprintf('<?php // %s', $first->id()->value()),
            sprintf('<?php // %s', $second->id()->value()),
        ])
        ->and(is_file(sprintf('%s/%s', $project, Workspace::checkedMutant($first->id())->value())))->toBeFalse()
        ->and($checker->warmUps())->toHaveCount(1);
});

it('checks no mutant of a mutator the ledgers learned never fails a check, nor one whose tests a check would not save', function (): void {
    $project = Scratch::directory();
    [$first, $second] = preOffered();
    $known = AnalyserHistory::of('fake')
        ->withRate(RejectionRate::of($first->mutator(), 50, 0))
        ->withRate(RejectionRate::of($second->mutator(), 50, 1))
        ->withTime(CheckTime::of(10, Seconds::of(1.0)));
    $checker = preCheckedBy($project, []);

    $none = preChecking($project, $checker, $known)->rejected(
        PreCheckables::of(preOffer($first), preOffer($second, 0.0)),
        ProcessCount::single(),
    );

    expect(count($none))->toBe(0)
        ->and($checker->asked())->toBe([])
        ->and($checker->warmUps())->toBe([]);
});

it('rejects nothing with no analyser, nothing offered, or no time left', function (): void {
    $project = Scratch::directory();
    [$first] = preOffered();
    $checker = preCheckedBy($project, []);
    $passed = Deadline::after(new DateTimeImmutable('2025-01-01T00:00:00Z'), Seconds::of(1.0));

    expect(count(preChecking($project, NoAnalyser::configured(), AnalyserHistory::of('fake'))->rejected(PreCheckables::of(preOffer($first)), ProcessCount::single())))->toBe(0)
        ->and(count(preChecking($project, $checker, AnalyserHistory::of('fake'))->rejected(PreCheckables::of(), ProcessCount::single())))->toBe(0)
        ->and(count(preChecking($project, $checker, AnalyserHistory::of('fake'), $passed)->rejected(PreCheckables::of(preOffer($first)), ProcessCount::single())))->toBe(0)
        ->and($checker->asked())->toBe([]);
});

it('judges a printed mutant against its file\'s print, checked once, where it analyses as the files do, and leaves one whose print does not', function (): void {
    $project = Scratch::directory();
    [$first, $second] = preOffered();
    $held = [...Flows::mutantsOf('src/Held.php')][0];
    $printed = static fn(Mutant $mutant): PreCheckable => PreCheckable::of(
        $mutant,
        Checkable::printed(Contents::of('<?php // the print'), Contents::of(sprintf('<?php // %s', $mutant->id()->value()))),
        Seconds::of(10.0),
    );
    $checker = preCheckedBy($project, [
        Workspace::checkedOriginal($first->id())->value() => preOriginals(),
        Workspace::checkedMutant($first->id())->value() => Findings::of(...preOriginals(), ...Findings::of(Finding::error(Path::of('src/Money.php'), 'new', 'New.'))),
        Workspace::checkedMutant($second->id())->value() => preOriginals(),
        Workspace::checkedOriginal($held->id())->value() => Findings::none(),
    ]);

    $rejections = preChecking($project, $checker, AnalyserHistory::of('fake'))->rejected(
        PreCheckables::of($printed($first), $printed($second), $printed($held)),
        ProcessCount::of(2),
    );

    expect(count($rejections))->toBe(1)
        ->and($rejections->of($first->id()))->toBeInstanceOf(Rejection::class)
        ->and(array_map(static fn(array $check): string => $check[1], $checker->asked()))->toBe([
            Workspace::checkedOriginal($first->id())->value(),
            Workspace::checkedOriginal($held->id())->value(),
            Workspace::checkedMutant($first->id())->value(),
            Workspace::checkedMutant($second->id())->value(),
        ])
        ->and(is_file(sprintf('%s/%s', $project, Workspace::checkedOriginal($first->id())->value())))->toBeFalse();
});

it('chooses by what this run has learned too: a mutator whose checks have now passed fifty times is checked no more', function (): void {
    $project = Scratch::directory();
    [$first] = preOffered();
    $known = AnalyserHistory::of('fake')->withRate(RejectionRate::of($first->mutator(), 49, 0));
    $checker = preCheckedBy($project, [Workspace::checkedMutant($first->id())->value() => preOriginals()]);
    $checking = preChecking($project, $checker, $known);

    $checking->rejected(PreCheckables::of(preOffer($first)), ProcessCount::single());
    $checking->rejected(PreCheckables::of(preOffer($first)), ProcessCount::single());

    expect($checker->asked())->toHaveCount(1);
});

it('learns the time of a check that gave no findings, and no rate; each check\'s share of checks run one at a time', function (): void {
    $project = Scratch::directory();
    [$first, $second] = preOffered();
    $checker = preCheckedBy($project, [
        Workspace::checkedMutant($first->id())->value() => OutOfScope::of(Path::of('src/Money.php')),
        Workspace::checkedMutant($second->id())->value() => preOriginals(),
    ]);
    $checking = preChecking($project, $checker, AnalyserHistory::of('fake'));

    $checking->rejected(PreCheckables::of(preOffer($first), preOffer($second)), ProcessCount::single());
    $learned = $checking->checks()->histories()->of(preIdentity());

    expect($learned->time()->checks())->toBe(2)
        ->and($learned->time()->seconds())->toEqual(Seconds::of(1.0))
        ->and($learned->rateOf($first->mutation())->checks())->toBe(0)
        ->and($learned->rateOf($second->mutation())->checks())->toBe(1);
});

it('runs each check without what the gate withholds, and lists the files a mutant that changes what its file declares can break', function (): void {
    $library = 'tests/Contract/Runner/phpunit-fixture/library/src';
    $files = [
        'src/Money.php' => (string) file_get_contents(Tree::at(sprintf('%s/Money.php', $library))),
        'src/Wallet.php' => "<?php\n\nnamespace Library;\n\nfinal class Wallet\n{\n    public function large(Money \$money): bool { return \$money->isLarge(1); }\n}\n",
    ];
    $project = Scratch::directory();

    foreach ($files as $path => $contents) {
        Scratch::write($project, $path, $contents);
    }

    [$first] = preOffered();
    $checkout = new ChangeSourceFake(Revision::ref('base'), Changes::none(), [Revision::workingTree()->name() => $files]);
    $checker = new RecordingChecker(new StaticCheckerFake(preIdentity(), preOriginals(), [], dependents: true), $project);
    $clock = new TickingClock('2026-01-01T00:00:00Z', 1);
    $retyped = PreCheckable::of(
        $first,
        Checkable::inPlace(Contents::of(str_replace('public function isLarge', 'protected function isLarge', $files['src/Money.php']))),
        Seconds::of(10.0),
    );

    new PreChecking(
        Flows::adapters($project, [], ScriptedRunner::fixture(), $checker, $checkout),
        $clock,
        Unlimited::time(),
        Seconds::of(60.0),
        AnalyserHistory::of('fake'),
        new AnalyserWarmUp(),
        new Stopwatch($clock, $clock->now()),
        PreCheck::standard(),
    )->rejected(PreCheckables::of($retyped), ProcessCount::single());

    expect($checker->dependents())->toBe([['src/Wallet.php']])
        ->and(preg_match($checker->withheld()[0], 'FAKE_CI_TOKEN'))->toBe(1);
});
