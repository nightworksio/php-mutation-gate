<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\PestSteps;
use NightWorksIO\MutationGate\Core\Config\ChildProcesses;
use NightWorksIO\MutationGate\Core\Config\Duplicates;
use NightWorksIO\MutationGate\Core\Config\LongFilter;
use NightWorksIO\MutationGate\Core\Config\NarrowedKillTrust;
use NightWorksIO\MutationGate\Core\Config\Preloaded;
use NightWorksIO\MutationGate\Core\Config\Toggles;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;

$holds = [
    'holds:src/Adapter/Pest',
];

it('kills a mutant only a PHP process its test starts tells apart where pest.childProcesses serves it, and leaves it surviving where it is off', function (): void {
    $files = [
        'src/Spawned.php' => 'tests/Contract/Runner/spawned/src/Spawned.php',
        'tests/SpawnedSpec.php' => 'tests/Contract/Runner/spawned/tests/SpawnedSpec.php',
        'spawned.php' => 'tests/Contract/Runner/spawned/spawned.php',
    ];

    foreach ($files as $placed => $shipped) {
        copy(Tree::at($shipped), Tree::at(sprintf('%s/%s', Library::DIRECTORY, $placed)));
    }

    Library::patched();
    $judged = [];

    try {
        foreach ([ChildProcesses::Serve, ChildProcesses::Off] as $children) {
            $result = Library::pestPlacing(Patching::on(Library::canary())->stepping(PestSteps::of(
                Toggles::none(),
                NarrowedKillTrust::Vouch,
                LongFilter::Files,
                Duplicates::JudgeOnce,
                preloaded: Preloaded::Fresh,
                childProcesses: $children,
            )))->runner()->mutate(
                MutationRequest::of(Paths::of(Path::of('src/Spawned.php')), WholeSuite::tests())->narrowedTo(
                    Paths::of(Path::of('src/Spawned.php')),
                    Narrowing::none()->toMutators(Mutators::named(PlusToMinus::class)),
                ),
            );
            $judged[$children->value] = array_map(
                static fn(Mutant $mutant): MutantStatus => $mutant->status(),
                $result instanceof MutationResult ? [...$result->mutants()] : [],
            );
        }
    } finally {
        foreach (array_keys($files) as $placed) {
            unlink(Tree::at(sprintf('%s/%s', Library::DIRECTORY, $placed)));
        }
    }

    expect($judged)->toBe(['serve' => [MutantStatus::Killed], 'off' => [MutantStatus::Survived]]);
})->skip(
    fn(): bool => ! Library::isInstalled(),
    'the runner contracts job installs the fixture library',
)->group(...$holds);
