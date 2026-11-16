<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\PestSteps;
use NightWorksIO\MutationGate\Adapter\Pest\ReadersOpening;
use NightWorksIO\MutationGate\Core\Config\Duplicates;
use NightWorksIO\MutationGate\Core\Config\LongFilter;
use NightWorksIO\MutationGate\Core\Config\NarrowedKillTrust;
use NightWorksIO\MutationGate\Core\Config\Toggle;
use NightWorksIO\MutationGate\Core\Config\Toggles;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestCases;
use NightWorksIO\MutationGate\Tests\Support\PestCases;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('readies the plan\'s map of the shards\' files with the line of each value they declare covered by the tests that read it', function (): void {
    $at = PestCases::project();
    Scratch::write($at->root(), 'src/Money.php', "<?php\n\nfinal class Money\n{\n    public const RATE = 2;\n}\n");
    Scratch::write($at->root(), 'tests/MoneySpec.php', "<?php\nit('adds', fn () => Money::RATE);\n");
    $whole = CoverageMap::of(
        CoveredLine::of(Path::of('src/Money.php'), 11, PestCases::RUN_ADDS),
        CoveredLine::of(Path::of('src/Other.php'), 3, 'Tests\B::c'),
    )->timedEach(TimedTest::of(PestCases::RUN_ADDS, 1.25), TimedTest::of('Tests\B::c', 2.0));

    expect(new ReadersOpening($at, PestCases::canary())->readied($whole, Paths::of(Path::of('src/Money.php')), TestCases::unknown()))
        ->toEqual(CoverageMap::of(
            CoveredLine::of(Path::of('src/Money.php'), 11, PestCases::RUN_ADDS),
            CoveredLine::of(Path::of('src/Money.php'), 5, PestCases::RUN_ADDS),
        )->timedEach(TimedTest::of(PestCases::RUN_ADDS, 1.25), TimedTest::of('Tests\B::c', 2.0)));
});

it('readies nothing for shards that open on their own suite, or that read no value', function (): void {
    $at = PestCases::project();
    $unread = PestCases::canary()->stepping(PestSteps::of(
        Toggles::of(Toggle::PestValueReaders, on: false),
        NarrowedKillTrust::Vouch,
        LongFilter::Files,
        Duplicates::JudgeOnce,
    ));
    $readied = static fn(Patching $patching): CoverageMap|NotGiven => new ReadersOpening($at, $patching)
        ->readied(CoverageMap::of(), Paths::none(), TestCases::unknown());

    expect($readied(Patching::off()))->toEqual(NotGiven::value())
        ->and($readied($unread))->toEqual(NotGiven::value());
});
