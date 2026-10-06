<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Bridges as InfectionBridges;
use NightWorksIO\MutationGate\Adapter\Infection\Infection;
use NightWorksIO\MutationGate\Adapter\Infection\ProcessShell as InfectionShell;
use NightWorksIO\MutationGate\Adapter\Infection\Project as InfectionProject;
use NightWorksIO\MutationGate\Adapter\Pest\Bridges as PestBridges;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\Pest;
use NightWorksIO\MutationGate\Adapter\Pest\ProcessShell as PestShell;
use NightWorksIO\MutationGate\Adapter\Pest\Project as PestProject;
use NightWorksIO\MutationGate\Adapter\Process\LocalProcesses;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Mutators\PlusToTimes;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Mutators\RemoveFinal;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;

// A mutator written once against the SDK makes its mutants under each runner
// that makes its own (ADR-0021): the Pest and Infection libraries autoload
// Mutators\PlusToTimes, and load the bridges their adapters write to it. Each
// mutant carries the mutator's own name and family, and runs again by that
// name. PlusToTimes handles an abstract node class, so Pest is offered it only
// where the bridge names the subclasses. RemoveFinal changes a class's
// declaration, outside every method, which Pest offers and Infection never
// does.

/** The registered mutators the contract turns on. */
function contractMutators(): Enabled
{
    return Enabled::of(MutatorSet::of(PlusToTimes::class, RemoveFinal::class));
}

/** The Pest adapter over the installed library, with the bridge to PlusToTimes. */
function bridgedPest(): Runner
{
    $root = Tree::at(Library::DIRECTORY);
    $project = PestProject::at($root, Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of('vendor'));

    return new Pest($project, new PestShell(new LocalProcesses(new SystemClock()), $root), Patching::off(), new CapDirectory(), Triage::standard()->bounds(), bridges: PestBridges::to(contractMutators()));
}

/** The Infection adapter over its installed library, with the bridge to PlusToTimes. */
function bridgedInfection(): Runner
{
    $root = Tree::at(Library::INFECTION_DIRECTORY);
    $project = InfectionProject::at(Root::of($root), Paths::of(Path::of('tests')), Path::of('.mutation-gate'));

    return new Infection(
        $project,
        new InfectionShell(new LocalProcesses(new SystemClock()), $root, getenv()),
        Triage::standard()->bounds(),
        nativeMarkersAllowed: false,
        files: new CapDirectory(),
        bridges: InfectionBridges::to(contractMutators()),
    );
}

/**
 * Each mutant of Money's sum, line 11, as the gate records it: its mutator, its family and its status.
 *
 * @return list<array{string, MutatorFamily, MutantStatus}>|CannotJudge
 */
function summed(MutationResult|Mutants|CannotJudge $result): array|CannotJudge
{
    $mutants = $result instanceof MutationResult ? $result->mutants() : $result;

    if ($mutants instanceof CannotJudge) {
        return $mutants;
    }

    $summed = [];

    foreach ($mutants as $mutant) {
        $summed = $mutant->location()->start()->number() === 11
            ? [...$summed, [$mutant->mutation()->mutator(), $mutant->mutation()->family(), $mutant->status()]]
            : $summed;
    }

    return $summed;
}

/** A request to mutate Money with these mutators. */
function moneyWith(Mutators $mutators): MutationRequest
{
    return MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($mutators));
}

/** @return list<string> each mutant's id in the gate's spelling */
function bridgedIds(Mutants|CannotJudge $mutants): array
{
    return $mutants instanceof Mutants
        ? array_map(static fn(Mutant $mutant): string => $mutant->id()->value(), [...$mutants])
        : [$mutants->why()];
}

/** The mutants of a run, none for one that cannot be judged. */
function bridgedMutants(MutationResult|CannotJudge $result): Mutants
{
    return $result instanceof MutationResult ? $result->mutants() : Mutants::none();
}

it('makes a registered mutator\'s mutant by its name, under its own name and family, and runs it again by that name, with Pest', function (): void {
    $result = bridgedPest()->mutate(moneyWith(Mutators::named('contract/PlusToTimes')));
    $retried = bridgedPest()->retry(moneyWith(Mutators::all()), bridgedMutants($result), Seconds::of(10.0));

    expect(summed($result))->toBe([['contract/PlusToTimes', MutatorFamily::Arithmetic, MutantStatus::Killed]])
        ->and(bridgedIds($retried))->toBe(bridgedIds(bridgedMutants($result)));
})->skip(! Library::isInstalled(), 'the runner contracts job installs the library');

it('makes a registered mutator\'s mutants beside its own in a run of every mutator, with Pest', function (): void {
    expect(summed(bridgedPest()->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()))))
        ->toContain(
            [PlusToMinus::class, MutatorFamily::Arithmetic, MutantStatus::Killed],
            ['contract/PlusToTimes', MutatorFamily::Arithmetic, MutantStatus::Killed],
        );
})->skip(! Library::isInstalled(), 'the runner contracts job installs the library');

it('makes a registered mutator\'s mutant by its name, under its own name and family, and runs it again by that name, with Infection', function (): void {
    $result = bridgedInfection()->mutate(moneyWith(Mutators::named('contract/PlusToTimes')));
    $retried = bridgedInfection()->retry(moneyWith(Mutators::all()), bridgedMutants($result), Seconds::of(10.0));

    expect(summed($result))->toBe([['contract/PlusToTimes', MutatorFamily::Arithmetic, MutantStatus::Killed]])
        ->and(bridgedIds($retried))->toBe(bridgedIds(bridgedMutants($result)));
})->skip(! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');

it('makes a registered mutator\'s mutants beside its own in a run of every mutator, with Infection', function (): void {
    expect(summed(bridgedInfection()->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()))))
        ->toContain(
            ['Plus', MutatorFamily::Arithmetic, MutantStatus::Killed],
            ['contract/PlusToTimes', MutatorFamily::Arithmetic, MutantStatus::Killed],
        );
})->skip(! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');

/**
 * Each mutant of a run, by its mutator and the line it starts on; why not, for a run that cannot be judged.
 *
 * @return list<array{string, int}>|list<string>
 */
function declared(MutationResult|CannotJudge $result): array
{
    return $result instanceof MutationResult
        ? array_map(
            static fn(Mutant $mutant): array => [$mutant->mutation()->mutator(), $mutant->location()->start()->number()],
            [...$result->mutants()],
        )
        : [$result->why()];
}

it('makes a registered mutator\'s mutant of a class\'s declaration, outside every method, with Pest', function (): void {
    expect(declared(bridgedPest()->mutate(moneyWith(Mutators::named('contract/RemoveFinal')))))->toBe([['contract/RemoveFinal', 7]]);
})->skip(! Library::isInstalled(), 'the runner contracts job installs the library');

it('offers a registered mutator no node outside a method, so makes no mutant of a class\'s declaration, with Infection', function (): void {
    expect(declared(bridgedInfection()->mutate(moneyWith(Mutators::named('contract/RemoveFinal')))))->toBe([]);
})->skip(! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');
