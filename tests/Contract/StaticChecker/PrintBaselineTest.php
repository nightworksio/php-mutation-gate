<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Printed;
use NightWorksIO\MutationGate\Adapter\PhpStan\PhpStan;
use NightWorksIO\MutationGate\Adapter\Process\LocalProcesses;
use NightWorksIO\MutationGate\Cli\Flow\Adapters;
use NightWorksIO\MutationGate\Cli\Flow\Dependents;
use NightWorksIO\MutationGate\Cli\Flow\NameGraph;
use NightWorksIO\MutationGate\Cli\Flow\PrintBaselines;
use NightWorksIO\MutationGate\Cli\Flow\WarmedUp;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\Analysis\DependentCap;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\Analysis\PreCheckable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Port\StaticChecker;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use PHPUnit\Framework\Assert;

$holds = [
    'holds:src/Cli/Flow/PrintBaselines.php',
    'holds:src/Core/Analysis/DifferingPrint.php',
];

/** The wired file's text, printed as Pest prints it. */
function wiredPrint(string $text): Contents
{
    $printed = Printed::of(Contents::of($text), Path::of('src/Composition/Wiring.php'));

    return $printed instanceof Contents ? $printed : Assert::fail('The wired file does not parse.');
}

/** A survivor of the wired file, its original and its mutant printed as Pest prints them, one line changed so. */
function wiredSurvivor(string $from, string $to): PreCheckable
{
    $file = Path::of('src/Composition/Wiring.php');
    $written = (string) file_get_contents(Tree::at(sprintf('tests/Contract/StaticChecker/fixture/%s', $file->value())));
    $diff = sprintf("@@ @@\n-%s\n+%s", $from, $to);

    return PreCheckable::of(
        Mutant::of(
            MutantId::hash($file, 'Change', $diff, 0),
            'Change-1',
            Location::of($file, Line::of(18), Line::of(18)),
            Mutation::of('Change', MutatorFamily::Literal, $diff),
            MutantStatus::Survived,
            Seconds::of(0.1),
        ),
        Checkable::printed(wiredPrint($written), wiredPrint(str_replace($from, $to, $written))),
        Seconds::of(1.0),
    );
}

/** PHPStan as the gate builds it over the fixture, where the analyser contracts job installed it. */
function wiredPhpStan(): StaticChecker
{
    if (getenv('ANALYSER_CONTRACTS') !== '1') {
        Assert::markTestSkipped('the analyser contracts job installs the analysers into the fixture');
    }

    $built = PhpStan::fromOptions(Configs::options('{}'), Tree::at('tests/Contract/StaticChecker/fixture'), new LocalProcesses(new SystemClock()));

    return $built instanceof StaticChecker ? $built : Assert::fail('The fixture\'s options are refused.');
}

/** The warm-up of an analyser over the fixture, as a run takes it. */
function wiredWarmUp(StaticChecker $checker, Adapters $adapters): WarmedUp
{
    $identity = $checker->identity($adapters->withheld);
    $findings = $checker->findings(Paths::none(), $adapters->withheld);

    return new WarmedUp(
        $checker,
        $identity instanceof AnalyserIdentity ? $identity : Assert::fail('The analyser says no version.'),
        $findings instanceof Findings ? $findings : Assert::fail('The analyser does not warm up.'),
        Seconds::of(1.0),
        new Dependents(new NameGraph($adapters), DependentCap::standard()),
    );
}

/**
 * The codes of the errors a survivor's check adds beyond a baseline; or why there are none to read.
 *
 * @return list<string>|string
 */
function wiredAdded(StaticChecker $checker, Adapters $adapters, PreCheckable $survivor, mixed $baseline): array|string
{
    $at = Workspace::checkedMutant($survivor->mutant()->id());
    $adapters->project->write($at, $survivor->checkable()->mutant());
    $found = $checker->check(MutantCheck::of($survivor->mutant()->location()->file(), $at)->within(Seconds::of(120.0)));
    $adapters->project->remove($at);

    return match (true) {
        ! $baseline instanceof Findings => 'no baseline',
        $found instanceof Findings => array_map(static fn(Finding $finding): string => $finding->code(), [...$found->newErrors($baseline)]),
        $found instanceof CannotJudge => $found->why(),
        default => 'out of scope',
    };
}

// The fixture's PHPStan forbids getenv() outside src/Composition, by a path
// glob a rule reads from the file it analyses, and src/Composition/Wiring.php
// calls it. A check reads a print, and a mutant, from the gate's own
// directory in the file's place, where the glob does not match, so there
// the call is forbidden. The print is weighed against the file as written
// read from that place, and stands: a mutant that adds no error is left to
// its tests, and one that adds one is rejected by it alone.
it('stands the print of a file a path glob allows a call in, and judges its mutants by what they add, with PHPStan', function (): void {
    $phpstan = wiredPhpStan();
    $adapters = Flows::adapters(Tree::at('tests/Contract/StaticChecker/fixture'), [], $phpstan);
    $kept = wiredSurvivor('return 2;', 'return 3;');
    $broken = wiredSurvivor('return 2;', 'return \'2\';');
    $baselines = new PrintBaselines($adapters, wiredWarmUp($phpstan, $adapters), Seconds::of(120.0))
        ->of([$kept, $broken], ProcessCount::single());
    $baseline = $baselines->baseline($kept);

    expect($baseline instanceof Findings ? array_map(static fn(Finding $finding): string => $finding->code(), [...$baseline]) : $baseline)
        ->toBe(['disallowed.function'])
        ->and(wiredAdded($phpstan, $adapters, $kept, $baseline))->toBe([])
        ->and(wiredAdded($phpstan, $adapters, $broken, $baseline))->toBe(['return.type']);
})->group(...$holds);
