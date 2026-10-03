<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Project\PhpUnitSuite;
use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Reporting;
use NightWorksIO\MutationGate\Cli\Flow\Stubbing;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\IdPrefix;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\Stub\Stub;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

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
        new Reporting(new Chosen(new FirstParty()->extend(new Extensions(Origin::of(FirstParty::PACKAGE)))), Variables::of([])),
    ))->stub($sought, Absent::setting()) : $sought;

    return $stub instanceof Stub || $stub instanceof CannotJudge ? $stub : CannotJudge::because($stub->why());
}

it('offers the ignore as a PHP config writes one where the project has no config file', function (): void {
    $stub = stubbingOf(Flows::project());

    expect($stub instanceof Stub ? $stub->printed() : $stub)
        ->toContain("\n    // Ignore::mutant('49e02fb39669', because: ''),\n");
});

it('cannot place a test with no covering file where the trees or the PHPUnit config cannot be read', function (string $broken): void {
    $project = Flows::project();

    if ($broken === 'config') {
        Scratch::write($project, 'phpunit.xml', '<phpunit');
    }

    $stub = $broken === 'trees' ? stubbingOf($project, new TreeSourceFake(CannotJudge::because('No trees.'))) : stubbingOf($project);

    $suite = PhpUnitSuite::declaredIn(Contents::of('<phpunit'), Path::of('phpunit.xml'));
    $unread = $suite instanceof CannotJudge ? $suite->why() : 'a suite';

    expect($stub instanceof CannotJudge ? $stub->why() : $stub)->toBe($broken === 'trees' ? 'No trees.' : $unread);
})->with(['trees', 'config']);
