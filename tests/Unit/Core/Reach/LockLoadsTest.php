<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\LoadedPackages;
use NightWorksIO\MutationGate\Core\Coverage\ProcessLoads;
use NightWorksIO\MutationGate\Core\Coverage\TestLoad;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Reach\LockLoads;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Tests\Support\Flows;

function lockLoadsMap(): CoverageMap
{
    return CoverageMap::of()
        ->covered(Path::of('src/One.php'), Line::of(3), TestId::of('A::a'))
        ->covered(Path::of('src/Two.php'), Line::of(4), TestId::of('A::b'))
        ->covered(Path::of('src/Three.php'), Line::of(5), TestId::of('B::a'))
        ->loading(LoadedPackages::of(
            ProcessLoads::of(TestLoad::of('A::a'), TestLoad::of('A::b', 'acme/one')),
            ProcessLoads::of(TestLoad::of('B::a')),
        ));
}

it('reaches the files the tests that loaded a moved package ran, and says what moved', function (): void {
    $reach = LockLoads::of(Listed::of('acme/one', 'acme/gone'), lockLoadsMap())
        ->reach(Reach::nothing(Packages::of(Flows::trees())), Path::of('composer.lock'));

    expect($reach->reaches(Unit::file(Path::of('src/Two.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::file(Path::of('src/One.php'))))->toBeFalse()
        ->and($reach->reaches(Unit::file(Path::of('src/Three.php'))))->toBeFalse()
        ->and([...$reach->reasons()])->toEqual([Reason::that(
            '`composer.lock` moved `acme/one`, `acme/gone`, and the tests that loaded them, or started a process that could have, run 1 files.',
        )]);
});

it('reaches no file where no test loaded what moved', function (): void {
    $reach = LockLoads::of(Listed::of('acme/gone'), lockLoadsMap())
        ->reach(Reach::nothing(Packages::of(Flows::trees())), Path::of('composer.lock'));

    expect($reach->reaches(Unit::file(Path::of('src/Two.php'))))->toBeFalse();
});

it('reaches the files a test that started a process ran, though it loaded nothing that moved', function (): void {
    $map = CoverageMap::of()
        ->covered(Path::of('src/Spawns.php'), Line::of(3), TestId::of('A::a'))
        ->covered(Path::of('src/Plain.php'), Line::of(4), TestId::of('A::b'))
        ->loading(LoadedPackages::of(ProcessLoads::of(TestLoad::of('A::a')->startingProcesses(), TestLoad::of('A::b'))));
    $reach = LockLoads::of(Listed::of('acme/one'), $map)
        ->reach(Reach::nothing(Packages::of(Flows::trees())), Path::of('composer.lock'));

    expect($reach->reaches(Unit::file(Path::of('src/Spawns.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::file(Path::of('src/Plain.php'))))->toBeFalse();
});
