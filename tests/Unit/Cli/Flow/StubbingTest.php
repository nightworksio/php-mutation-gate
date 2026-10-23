<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Reporting;
use NightWorksIO\MutationGate\Cli\Flow\Stubbing;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\IdPrefix;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Stub\Stub;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;
use NightWorksIO\MutationGateSecurity\Mutators\HashEqualsToIdentical;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * A stub for the survivor of `src/Money.php`'s sixteenth line, from the
 * default branch's ledger alone, in a project with no config file, over
 * these trees.
 */
function stubbingOf(string $project, object ...$ports): Stub|CannotJudge
{
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()->withProof(Proof::of(
        Digest::sha256Of('src/Money.php'),
        Path::of('src/Money.php'),
        Flows::mutantsOf('src/Money.php'),
        Run::of('github:7/1', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('base')),
    )));
    $sought = IdPrefix::parse('49e02f');
    $stub = $sought instanceof IdPrefix ? new Stubbing(new Composed(
        Flows::settings(),
        Flows::adapters($project, [], $store, ...$ports),
        Flows::setup(),
        new Reporting(new Chosen(new FirstParty()->extend(new Extensions(Origin::of(ThisPackage::COMPOSER)))), Variables::of([])),
    ))->stub($sought, Absent::setting()) : $sought;

    return $stub instanceof Stub || $stub instanceof CannotJudge ? $stub : CannotJudge::because($stub->why());
}

it('offers the ignore as a PHP config writes one where the project has no config file', function (): void {
    $stub = stubbingOf(Flows::project());

    expect($stub instanceof Stub ? $stub->printed() : $stub)
        ->toContain("\n    // Ignore::mutant('49e02fb39669', because: ''),\n");
});

it('writes no stub where the runner\'s tests are in no style a stub is written in, as Codeception\'s are', function (): void {
    $runner = RunnerFake::ofTheFixture()->behaving(RunnerBehaviour::standard()->writingTestsIn(NotGiven::value()));
    $stub = stubbingOf(Flows::project(), $runner);

    expect($stub instanceof CannotJudge ? $stub->why() : $stub)->toBe('stub writes Pest, PHPUnit and PhpSpec tests, and none for this runner.');
});

it('cannot place a test with no covering file where the trees cannot be read', function (): void {
    $stub = stubbingOf(Flows::project(), new TreeSourceFake(CannotJudge::because('No trees.')));

    expect($stub instanceof CannotJudge ? $stub->why() : $stub)->toBe('No trees.');
});

/** A stub for a survivor of the security set's constant-time mutator, with these mutators registered and turned on. */
function stubbingPinned(Enabled $mutators): Stub|CannotJudge
{
    $project = Flows::project();
    $mutant = Verdicts::mutant(
        'src/Money.php:16',
        'security/HashEqualsToIdentical',
        MutatorFamily::Condition,
        Verdicts::diff('return \hash_equals($a, $b);', 'return $a === $b;'),
    );
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()->withProof(Proof::of(
        Digest::sha256Of('src/Money.php'),
        Path::of('src/Money.php'),
        Mutants::of($mutant),
        Run::of('github:7/1', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('base')),
    )));
    $sought = IdPrefix::parse(mb_substr($mutant->id()->value(), 0, 8));
    $stub = $sought instanceof IdPrefix ? new Stubbing(new Composed(
        Flows::settings(),
        Flows::adapters($project, [], $store, $mutators),
        Flows::setup(),
        new Reporting(new Chosen(new FirstParty()->extend(new Extensions(Origin::of(ThisPackage::COMPOSER)))), Variables::of([])),
    ))->stub($sought, Absent::setting()) : $sought;

    return $stub instanceof Stub || $stub instanceof CannotJudge ? $stub : CannotJudge::because($stub->why());
}

it('runs the code and reads its file where the survivor\'s mutator is pinned by source, and not where none registered is', function (): void {
    $pinned = stubbingPinned(Enabled::of(MutatorSet::of(HashEqualsToIdentical::class)));
    $unpinned = stubbingPinned(Enabled::of(MutatorSet::of()));

    expect($pinned instanceof Stub ? $pinned->printed() : $pinned)
        ->toContain('// Run it, then read the file it is in, which the mutant changes:')
        ->toContain("toContain('hash_equals(');")
        ->and($unpinned instanceof Stub ? $unpinned->printed() : $unpinned)
        ->not->toContain('// Run it, then read the file it is in, which the mutant changes:')
        ->toContain('// Assert on its result:');
});
