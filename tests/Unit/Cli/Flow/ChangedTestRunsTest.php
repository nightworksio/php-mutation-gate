<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\ChangedTestRuns;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Config\ChangedTests;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Reach\Layout;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\TestRuns;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;

/** What the tests a fake runner places in each changed test file cover, by a map of money and clock tests. */
function changedTestRuns(Changes|CannotTell $changes, ChangedTests $mode = ChangedTests::Narrow): TestRuns
{
    $map = CoverageMap::of()
        ->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('MoneyTest::adds'))
        ->covered(Path::of('src/Money.php'), Line::of(4), TestId::of('ClockTest::ticks'))
        ->covered(Path::of('src/Held.php'), Line::of(2), TestId::of('ClockTest::ticks'), TestId::of('HeldTest::holds'));
    $runner = RunnerFake::of(
        Identity::of('fake', Versions::none(), Digest::of('php')),
        Groups::of(),
        $map,
        Mutants::none(),
        Paths::none(),
        Paths::of(Path::of('phpunit.xml')),
        TestNames::none()
            ->with(TestId::of('MoneyTest::adds'), TestName::in(Path::of('tests/MoneyTest.php'), 'adds'))
            ->with(TestId::of('ClockTest::ticks'), TestName::in(Path::of('tests/ClockTest.php'), 'ticks'))
            ->with(TestId::of('HeldTest::holds'), TestName::in(Path::of('src/Held.php'), 'holds')),
        Paths::none(),
    );

    return ChangedTestRuns::of(
        $mode,
        $changes,
        Layout::standard(Paths::none()),
        Packages::of(Flows::trees()),
        Flows::adapters(Flows::project(), [], $runner),
        $map,
    );
}

it('places the map\'s tests in each changed test file, by the runner, and none in a changed file that holds no test', function (): void {
    $runs = changedTestRuns(Changes::of(
        Change::modified(Path::of('tests/MoneyTest.php'), Lines::of(Line::of(4))),
        Change::modified(Path::of('src/Held.php'), Lines::of(Line::of(2))),
        Change::modified(Path::of('tests/RateTest.php'), Lines::of(Line::of(1))),
    ));

    expect($runs->narrows())->toBeTrue()
        ->and($runs->in(Path::of('tests/MoneyTest.php')))->toEqual(TestIds::of(TestId::of('MoneyTest::adds')))
        ->and($runs->in(Path::of('tests/ClockTest.php')))->toEqual(TestIds::none())
        ->and($runs->in(Path::of('tests/RateTest.php')))->toEqual(TestIds::none())
        ->and($runs->in(Path::of('src/Held.php')))->toEqual(TestIds::none())
        ->and($runs->filesRunBy(TestIds::of(TestId::of('MoneyTest::adds'))))->toEqual(Paths::of(Path::of('src/Money.php')))
        ->and($runs->filesRunBy(TestIds::of(TestId::of('ClockTest::ticks'))))->toEqual(Paths::none());
});

it('places no test where git cannot tell what changed', function (): void {
    $runs = changedTestRuns(CannotTell::because('git gave no answer.'));

    expect($runs->narrows())->toBeTrue()
        ->and($runs->in(Path::of('tests/MoneyTest.php')))->toEqual(TestIds::none());
});

it('places no test and narrows nothing where reach.changedTests asks for the whole file', function (): void {
    $runs = changedTestRuns(Changes::of(Change::modified(Path::of('tests/MoneyTest.php'), Lines::of(Line::of(4)))), ChangedTests::File);

    expect($runs->narrows())->toBeFalse()
        ->and($runs->in(Path::of('tests/MoneyTest.php')))->toEqual(TestIds::none());
});
