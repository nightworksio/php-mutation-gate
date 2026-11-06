<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Key\VerdictCode;
use NightWorksIO\MutationGate\Core\Proof\Key\VerdictSet;

$reads = static fn(string $runner, string $file): bool => array_any(
    VerdictCode::of($runner),
    static fn(Glob $glob): bool => $glob->matches(Path::of($file)),
);

it('reads every run\'s sets and the runner\'s own, and every built-in runner\'s for an extension', function () use ($reads): void {
    expect([
        $reads('pest', 'src/Core/Mutant/Mutant.php'),
        $reads('pest', 'src/Adapter/Pest/MutationRun.php'),
        $reads('pest', 'src/Adapter/Infection/MutationRun.php'),
        $reads('pest', 'src/Adapter/Native/MutantRun.php'),
        $reads('phpunit', 'src/Adapter/Native/MutantRun.php'),
        $reads('phpunit', 'src/Adapter/PhpUnit/PhpUnit.php'),
        $reads('infection', 'src/Cli/InfectionPatch.php'),
        $reads('acme', 'src/Adapter/Testo/Testo.php'),
        $reads('acme', 'src/Adapter/Pest/MutationRun.php'),
        $reads('pest', 'src/Core/Report/Report.php'),
        $reads('pest', 'src/Cli/Flow/Planning.php'),
        $reads('pest', 'src/Adapter/PhpUnit/IssueKillers.php'),
        $reads('infection', 'src/Adapter/PhpUnit/IssueKillers.php'),
    ])->toBe([true, true, false, false, true, true, true, true, true, false, false, true, false])
        ->and(VerdictCode::SEMANTICS)->toBe(1)
        ->and(VerdictCode::pluginCode()->matches(Path::of('src/Arithmetic/PlusToMinus.php')))->toBeTrue()
        ->and(VerdictCode::pluginCode()->matches(Path::of('tests/PlusToMinusTest.php')))->toBeFalse();
});

it('reads exactly every run\'s sets and the runner\'s own', function (string $runner, array $own): void {
    /** @var list<VerdictSet> $own */
    $sets = [VerdictSet::Records, VerdictSet::Mutators, VerdictSet::Processes, VerdictSet::Analysers, VerdictSet::Running, ...$own];

    expect(VerdictCode::of($runner))->toEqual(array_merge(...array_map(static fn(VerdictSet $set): array => $set->globs(), $sets)));
})->with([
    'pest' => ['pest', [VerdictSet::Pest]],
    'infection' => ['infection', [VerdictSet::Infection]],
    'phpunit' => ['phpunit', [VerdictSet::PhpUnit, VerdictSet::Native]],
    'phpspec' => ['phpspec', [VerdictSet::PhpSpec, VerdictSet::Native]],
    'codeception' => ['codeception', [VerdictSet::Codeception, VerdictSet::Native]],
    'testo' => ['testo', [VerdictSet::Testo, VerdictSet::Native]],
]);
