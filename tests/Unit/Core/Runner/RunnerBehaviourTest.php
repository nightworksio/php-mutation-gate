<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\Cost\MutantSites;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Matrix\NotFull;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ExactRun;
use NightWorksIO\MutationGate\Core\Runner\HandedAsIs;
use NightWorksIO\MutationGate\Core\Runner\HoldsReading;
use NightWorksIO\MutationGate\Core\Runner\Parallelism;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Runner\ShardOpening;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestCases;
use NightWorksIO\MutationGate\Tests\Support\EveryRowOfTheMethod;

it('lists holds as groups, has no group in every key and opens no shard of its own, by default', function (): void {
    $standard = RunnerBehaviour::standard();

    expect($standard->opensEachShard())->toBeFalse()
        ->and($standard->opening())->toBeInstanceOf(HandedAsIs::class)
        ->and($standard->holds())->toBe(HoldsReading::Written)
        ->and($standard->readByEveryKey())->toEqual(Groups::none())
        ->and($standard->whyNotFull())->toBe(NotFull::FirstKillers)
        ->and($standard->parallelism())->toBe(Parallelism::Serial)
        ->and($standard->testStyle())->toBe(AssertionStyle::PhpUnit);
});

it('writes its tests in the style it is told', function (): void {
    expect(RunnerBehaviour::standard()->writingTestsIn(AssertionStyle::Pest)->testStyle())->toBe(AssertionStyle::Pest)
        ->and(RunnerBehaviour::standard()->writingTestsIn(NotGiven::value())->testStyle())->toEqual(NotGiven::value());
});

it('says each way it behaves otherwise, and nothing more', function (): void {
    $canary = Group::named('mutation-canary');
    $pest = RunnerBehaviour::standard()
        ->holdingAsLoaded()
        ->readingInEveryKey($canary)
        ->openingEachShard();

    expect($pest->opensEachShard())->toBeTrue()
        ->and($pest->holds())->toBe(HoldsReading::AsLoaded)
        ->and(RunnerBehaviour::standard()->readingHolds(HoldsReading::Listed)->holds())->toBe(HoldsReading::Listed)
        ->and(RunnerBehaviour::standard()->runningPerCore()->readingHolds(HoldsReading::Listed)->parallelism())
        ->toBe(Parallelism::PerCore)
        ->and($pest->readByEveryKey())->toEqual(Groups::of($canary))
        ->and(RunnerBehaviour::standard()->openingEachShard()->holds())->toBe(HoldsReading::Written)
        ->and(RunnerBehaviour::standard()->stoppingAtFirstKiller(NotFull::Infection)->whyNotFull())
        ->toBe(NotFull::Infection)
        ->and(RunnerBehaviour::standard()->stoppingAtFirstKiller(NotFull::Infection)->opensEachShard())->toBeFalse()
        ->and(RunnerBehaviour::standard()->runningPerCore()->parallelism())->toBe(Parallelism::PerCore)
        ->and(RunnerBehaviour::standard()->runningPerCore()->opensEachShard())->toBeFalse();
});

it('records first killers always, and every killer unless it is Infection', function (): void {
    $infection = RunnerBehaviour::standard()->stoppingAtFirstKiller(NotFull::Infection);

    expect(RunnerBehaviour::standard()->records(MatrixKind::Full))->toBeTrue()
        ->and(RunnerBehaviour::standard()->records(MatrixKind::FirstKiller))->toBeTrue()
        ->and(RunnerBehaviour::standard()->stoppingAtFirstKiller(NotFull::FirstKillers)->records(MatrixKind::Full))->toBeTrue()
        ->and($infection->records(MatrixKind::FirstKiller))->toBeTrue()
        ->and($infection->records(MatrixKind::Full))->toBeFalse();
});

it('selects exactly the tests that judge a mutant for its own run, unless the runner selects them otherwise', function (): void {
    expect(RunnerBehaviour::standard()->selection())->toEqual(new ExactRun())
        ->and(RunnerBehaviour::standard()->selecting(new EveryRowOfTheMethod())->selection())->toEqual(new EveryRowOfTheMethod())
        ->and(RunnerBehaviour::standard()->selecting(new EveryRowOfTheMethod())->runningPerCore()->selection())->toEqual(new EveryRowOfTheMethod());
});

it('opens each shard on the map it is handed, unless the runner readies the map once, as the plan is made', function (): void {
    $readied = new class implements ShardOpening {
        public function readied(CoverageMap $whole, Paths $files, TestCases $cases): CoverageMap
        {
            return $whole;
        }

        public function made(Paths $files, Mutators $mutators, Withheld $withheld): MutantSites
        {
            return MutantSites::inFile(Path::of('src/Money.php'), Line::of(3));
        }
    };
    $map = CoverageMap::of();

    expect(RunnerBehaviour::standard()->opening()->readied($map, Paths::none(), TestCases::unknown()))->toEqual(NotGiven::value())
        ->and(RunnerBehaviour::standard()->opening()->made(Paths::of(Path::of('src/Money.php')), Mutators::all(), Withheld::nothing()))->toEqual(MutantSites::none())
        ->and(RunnerBehaviour::standard()->readyingOpening($readied)->opening())->toBe($readied)
        ->and(RunnerBehaviour::standard()->readyingOpening($readied)->runningPerCore()->selecting(new ExactRun())->opening())->toBe($readied);
});

it('loads every test file for a control of some tests alone, as a runner does that places none', function (): void {
    expect(new ExactRun()->loadsTheFilesOfItsTests())->toBeFalse()
        ->and(new ExactRun()->replaysKills())->toBeFalse();
});
