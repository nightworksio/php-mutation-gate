<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\Controls;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Invocation;
use NightWorksIO\MutationGate\Adapter\Pest\Pest;
use NightWorksIO\MutationGate\Adapter\Pest\Printed;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Selection;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Support\PestCases;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('leaves unjudged a kill whose named killer fails as well with the file unmutated, served through Pest\'s override as the mutant was', function (): void {
    $at = PestCases::project();
    $shell = PestCases::overrideSensitive($at, sensitive: true);

    $result = new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds())->mutate(PestCases::money());
    $mutants = $result instanceof MutationResult ? [...$result->mutants()] : [];

    expect(PestCases::statuses($result))->toBe([MutantStatus::Unjudged])
        ->and($mutants[0]->reason())->toEqual(Reason::that(Controls::FAILS_UNMUTATED))
        ->and(PestCases::controls($shell))->toHaveCount(2);
});

it('leaves unjudged a kill no test is named for, confirmed again so, whose control fails with the file unmutated', function (): void {
    $at = PestCases::project();
    $shell = PestCases::overrideSensitive($at, sensitive: true, named: false);

    $result = new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds())->mutate(PestCases::money());

    expect(PestCases::statuses($result))->toBe([MutantStatus::Unjudged])
        ->and(PestCases::controls($shell))->toHaveCount(1);
});

it('counts a kill confirmed again whose control passes with the file unmutated, served through Pest\'s override', function (bool $named): void {
    $at = PestCases::project();
    $shell = PestCases::overrideSensitive($at, sensitive: false, named: $named);

    $result = new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds())->mutate(PestCases::money());

    expect(PestCases::statuses($result))->toBe([MutantStatus::Killed]);
})->with([
    'named' => [true],
    'unnamed' => [false],
]);

it('runs each control with the file the mutant changes served unmutated, the narrowed files\' tests as the run judged them, then the covering tests as Pest\'s filter selects them from every test file', function (): void {
    $at = PestCases::project();
    $shell = PestCases::overrideSensitive($at, sensitive: true);

    new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds())->mutate(PestCases::money());
    [$baseline, $confirmation] = PestCases::controls($shell);
    $printed = Printed::of(Contents::of((string) file_get_contents(sprintf('%s/src/Money.php', $at->root()))), Path::of('src/Money.php'));
    $unmutated = sprintf('%s/.mutation-gate/pest/originals/%s.php', $at->root(), hash('xxh3', $printed instanceof Contents ? $printed->text() : ''));
    $served = [Recorder::MUTANT => sprintf('%s/src/Money.php', $at->root()), Recorder::MUTATED => $unmutated];
    $judging = Invocation::installedIn(Path::of('vendor'));

    expect($baseline)->toEqual($judging->judging(Paths::of(Path::of(PestCases::spec($at))), WholeSuite::tests(), Withheld::standard())->with($served))
        ->and($confirmation)->toEqual(
            $judging->judging(Paths::none(), Selection::of(TestIds::of(TestId::of(PestCases::RUN_ADDS)))->filter(), Withheld::standard())->with($served),
        )
        ->and(file_get_contents($unmutated))->toBe($printed instanceof Contents ? $printed->text() : null);
});

it('runs a held unit\'s narrowed kill\'s control by the group that holds it, as its run was judged', function (): void {
    $at = PestCases::project();
    $shell = PestCases::overrideSensitive($at, sensitive: false);

    new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds())
        ->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), Group::named('holds:src/Money.php')));

    expect(PestCases::controls($shell)[0]->arguments())->toContain('--group=holds:src/Money.php');
});

it('vouches for a narrowed kill whose order is known by replaying its run unmutated, and leaves it unjudged where the replay took another order', function (bool $sameOrder, MutantStatus $status): void {
    $at = PestCases::project();
    $shell = PestCases::replayed($at, $sameOrder);

    $result = new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds())->mutate(PestCases::money());
    $replays = array_filter(
        $shell->commands(),
        static fn(Command $command): bool => ($command->environment()[GateVariable::StopAfter->value] ?? false) !== false,
    );

    expect(PestCases::statuses($result))->toBe([$status])
        ->and($replays)->toHaveCount(1)
        ->and(PestCases::controls($shell))->toBe([]);
})->with([
    'the same order' => [true, MutantStatus::Killed],
    'another order' => [false, MutantStatus::Unjudged],
]);
