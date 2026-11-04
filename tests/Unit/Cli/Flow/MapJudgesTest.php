<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\MapJudges;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Support\MisbehavingRunner;

/** A map covering src/Money.php and src/Rate.php, in that order. */
function judgedMap(): CoverageMap
{
    return CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('MoneyTest::adds'))
        ->covered(Path::of('src/Rate.php'), Line::of(5), TestId::of('RateTest::rounds'));
}

/**
 * A runner that judges src/Money.php by MoneyTest and SharedTest, cannot tell
 * of src/Rate.php, and judges any other file by OtherTest, writing down each
 * file it is asked of.
 *
 * @param ArrayObject<int, string> $asked
 */
function judgingRunner(ArrayObject $asked): MisbehavingRunner
{
    return new MisbehavingRunner(
        RunnerFake::ofTheFixture(),
        judges: static function (Path $file) use ($asked): Paths|CannotJudge {
            $asked->append($file->value());

            return match ($file->value()) {
                'src/Money.php' => Paths::of(Path::of('tests/MoneyTest.php'), Path::of('tests/SharedTest.php')),
                'src/Rate.php' => CannotJudge::because('Rate cannot be told.'),
                default => Paths::of(Path::of('tests/OtherTest.php')),
            };
        },
    );
}

it('asks the runner of each covered file once, however often reach, the content keys and the unit keys read it', function (): void {
    $asked = new ArrayObject();
    $judges = MapJudges::of(judgingRunner($asked), judgedMap());

    $judges->judges();
    $judges->known();
    $judges->for(Path::of('src/Money.php'));
    $judges->for(Path::of('src/Rate.php'));

    expect($asked->getArrayCopy())->toBe(['src/Money.php', 'src/Rate.php'])
        ->and($judges->for(Path::of('src/Money.php')))
        ->toEqual(Paths::of(Path::of('tests/MoneyTest.php'), Path::of('tests/SharedTest.php')))
        ->and($judges->for(Path::of('src/Rate.php')))->toEqual(CannotJudge::because('Rate cannot be told.'));
});

it('asks the runner of a file the map does not cover each time it is read', function (): void {
    $asked = new ArrayObject();
    $judges = MapJudges::of(judgingRunner($asked), judgedMap());

    expect($judges->for(Path::of('src/Gone.php')))->toEqual(Paths::of(Path::of('tests/OtherTest.php')))
        ->and($judges->for(Path::of('src/Gone.php')))->toEqual(Paths::of(Path::of('tests/OtherTest.php')))
        ->and($asked->getArrayCopy())->toBe(['src/Money.php', 'src/Rate.php', 'src/Gone.php', 'src/Gone.php']);
});

it('leaves a covered file the runner cannot tell of out of the judges and the known test files', function (): void {
    $judges = MapJudges::of(judgingRunner(new ArrayObject()), judgedMap());

    expect($judges->judges()->of(Path::of('src/Money.php')))
        ->toEqual(Paths::of(Path::of('tests/MoneyTest.php'), Path::of('tests/SharedTest.php')))
        ->and($judges->judges()->of(Path::of('src/Rate.php')))->toEqual(Paths::none())
        ->and($judges->judges()->filesRunBy(Path::of('tests/SharedTest.php')))->toEqual(Paths::of(Path::of('src/Money.php')))
        ->and($judges->known())->toEqual(Paths::of(Path::of('tests/MoneyTest.php'), Path::of('tests/SharedTest.php')))
        ->and($judges->map())->toEqual(judgedMap());
});
