<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Uncommitted;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Port\Repository;
use NightWorksIO\MutationGate\Tests\Fakes\RepositoryFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A plan over the project, with these ports in place of the fakes. */
$plan = (static fn(string $project, Mode $mode, Cut $cut, object ...$ports): Plan|CannotJudge => Planned::from(new Planning(
    Flows::adapters($project, [], ...$ports),
    Flows::settings(),
    Flows::setup(),
)->plan($mode, CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), $cut, MatrixKind::FirstKiller)));

it('hands the plan the digests of the run\'s inputs, with each unit it runs', function () use ($plan): void {
    $planned = $plan(Flows::project(), Mode::full(), Cut::exactly(1));
    $digests = $planned instanceof Plan ? $planned->digests() : $planned;

    expect($digests)->toBeInstanceOf(Digests::class)
        ->and($digests instanceof Digests ? $digests->sources()->paths() : $digests)
        ->toEqual(Paths::of(Path::of('src/Held.php'), Path::of('src/Money.php')))
        ->and($digests instanceof Digests ? count($digests->tests()) : $digests)->toBeGreaterThan(0)
        ->and($digests instanceof Digests ? $digests->commit() : $digests)->toEqual(Revision::ref(Flows::HEAD));
});

it('takes the digests at no commit where the working tree holds what HEAD does not, or git cannot say', function (
    RepositoryFake $repository,
) use ($plan): void {
    $planned = $plan(Flows::project(), Mode::full(), Cut::exactly(1), $repository);
    $digests = $planned instanceof Plan ? $planned->digests() : $planned;

    expect($digests instanceof Digests ? $digests->commit() : $digests)->toEqual(Uncommitted::tree());
})->with([
    'a changed working tree' => fn(): RepositoryFake => RepositoryFake::onMain(Revision::ref(Flows::HEAD))->changed(),
    'git cannot say' => fn(): RepositoryFake => RepositoryFake::onMain(Revision::ref(Flows::HEAD))->unsure(),
]);

it('takes the digests at no commit where HEAD moved while the plan was made, or git can no longer name it', function (
    Revision|CannotTell $later,
) use ($plan): void {
    $moving = new class ($later) implements Repository {
        private int $asked = 0;

        public function __construct(private readonly Revision|CannotTell $later)
        {
        }

        public function head(): Revision|CannotTell
        {
            $this->asked++;

            return $this->asked === 1 ? Revision::ref(Flows::HEAD) : $this->later;
        }

        public function isClean(): bool
        {
            return true;
        }

        public function branch(): Scope
        {
            return Scope::branch('main');
        }

        public function defaultBranch(): Scope
        {
            return Scope::branch('main');
        }
    };
    $planned = $plan(Flows::project(), Mode::full(), Cut::exactly(1), $moving);
    $digests = $planned instanceof Plan ? $planned->digests() : $planned;

    expect($planned instanceof Plan ? $planned->commit() : $planned)->toEqual(Revision::ref(Flows::HEAD))
        ->and($digests instanceof Digests ? $digests->commit() : $digests)->toEqual(Uncommitted::tree());
})->with([
    'another commit' => fn(): Revision => Revision::ref(str_repeat('c1', 20)),
    'git cannot say' => fn(): CannotTell => CannotTell::because('git rev-parse failed.'),
]);
