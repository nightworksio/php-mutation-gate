<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\MeasuredCosts;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Shards;
use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\Cost\FirstRun;
use NightWorksIO\MutationGate\Core\Cost\LineRate;
use NightWorksIO\MutationGate\Core\Cost\LinesOfCode;
use NightWorksIO\MutationGate\Core\Cost\MutantSites;
use NightWorksIO\MutationGate\Core\Cost\SecondsPerLine;
use NightWorksIO\MutationGate\Core\Cost\Shares;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Proof\Timing;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

const COSTED_MONEY = <<<'PHP'
    <?php

    // What a sum is.
    final class Money
    {
        public function add(): int
        {
            return 1;
        }
    }
    PHP;

const COSTED_LIMIT = <<<'PHP'
    <?php

    function limit(): int
    {
        return 2;
    }
    PHP;

/** A first run the plan measured of src/Money.php: two mutants on line 3, which a test of 0.5 s covers. */
function measuredMoneyRun(): FirstRun
{
    $money = Path::of('src/Money.php');
    $test = TestId::of('MoneyTest::adds');

    return FirstRun::of(
        CoverageMap::empty()->covered($money, Line::of(3), $test)->timed($test, Seconds::of(0.5)),
        MutantSites::inFile($money, Line::of(3), Line::of(3)),
        Seconds::of(1.5),
        ProcessCount::of(2),
    );
}

$linesOf = static fn(string $source): int => LinesOfCode::in(Contents::of($source));

$at = static fn(): Instant => Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z'));

it('costs a unit what a shard last measured it to take', function () use ($at): void {
    $learned = Timings::of(Timing::of(Path::of('src/Money.php'), Seconds::of(12.4), 'pest', $at()));

    $model = MeasuredCosts::at(Root::of(Scratch::directory()), SecondsPerLine::standard());

    expect($model->cost(Unit::file(Path::of('src/Money.php')), $learned, measuredMoneyRun()))
        ->toEqual(Estimated::of(Seconds::of(12.4), CostBasis::Learned));
});

it('costs a unit a shard timed by what it learned, whatever the plan measured of its first run', function () use ($at): void {
    $learned = Timings::of(Timing::of(Path::of('src/Money.php'), Seconds::of(12.4), 'pest', $at()));
    $model = MeasuredCosts::at(Root::of(Scratch::directory()), SecondsPerLine::standard());

    expect($model->cost(Unit::file(Path::of('src/Money.php')), $learned, measuredMoneyRun()))
        ->toEqual(Estimated::of(Seconds::of(12.4), CostBasis::Learned));
});

it('costs a unit no shard measured what the plan measured of its first run', function (): void {
    $model = MeasuredCosts::at(Root::of(Scratch::directory()), SecondsPerLine::standard());

    // Two mutants on a line one test of 0.5 s covers, each starting in 1.5 s, over two processes.
    expect($model->cost(Unit::file(Path::of('src/Money.php')), Timings::none(), measuredMoneyRun()))
        ->toEqual(Estimated::of(Seconds::of(2.0), CostBasis::Measured));
});

it('estimates a unit no shard measured as its lines of code times the seconds a line costs', function () use (
    $linesOf,
): void {
    $root = Scratch::directory();
    Scratch::write($root, 'src/Money.php', COSTED_MONEY);
    $rates = SecondsPerLine::of(LineRate::of('', Seconds::of(0.5)));

    expect($linesOf(COSTED_MONEY))->toBeGreaterThan(1)
        ->and(MeasuredCosts::at(Root::of($root), $rates)->cost(Unit::file(Path::of('src/Money.php')), Timings::none(), FirstRun::unmeasured()))
        ->toEqual(Estimated::of(Seconds::of($linesOf(COSTED_MONEY) * 0.5), CostBasis::Guessed));
});

it('estimates a line at the rate of the longest prefix its path is under', function () use ($linesOf): void {
    $root = Scratch::directory();
    Scratch::write($root, 'src/Http/Money.php', COSTED_MONEY);
    $rates = SecondsPerLine::of(LineRate::of('', Seconds::of(0.2)), LineRate::of('src/Http', Seconds::of(3.0)));

    expect(MeasuredCosts::at(Root::of($root), $rates)->cost(Unit::file(Path::of('src/Http/Money.php')), Timings::none(), FirstRun::unmeasured()))
        ->toEqual(Estimated::of(Seconds::of($linesOf(COSTED_MONEY) * 3.0), CostBasis::Guessed));
});

it('costs a file that is not there nothing', function (): void {
    $model = MeasuredCosts::at(Root::of(Scratch::directory()), SecondsPerLine::standard());

    expect($model->cost(Unit::file(Path::of('src/Gone.php')), Timings::none(), FirstRun::unmeasured()))
        ->toEqual(Estimated::of(Seconds::of(0.0), CostBasis::Guessed));
});

it('estimates a held directory as the PHP files under it, however deep', function () use ($linesOf): void {
    $root = Scratch::directory();
    Scratch::write($root, 'src/Kernel/Money.php', COSTED_MONEY);
    Scratch::write($root, 'src/Kernel/Deep/limit.php', COSTED_LIMIT);
    Scratch::write($root, 'src/Kernel/notes.txt', 'Words a tokenizer would read as a line of text.');
    Scratch::write($root, 'src/Other.php', COSTED_MONEY);
    $rates = SecondsPerLine::of(LineRate::of('', Seconds::of(1.0)));

    $kernel = Unit::held(Path::of('src/Kernel'), Group::named('holds:kernel'));

    expect(MeasuredCosts::at(Root::of($root), $rates)->cost($kernel, Timings::none(), FirstRun::unmeasured()))
        ->toEqual(Estimated::of(Seconds::of(($linesOf(COSTED_MONEY) + $linesOf(COSTED_LIMIT)) * 1.0), CostBasis::Guessed))
        ->and($linesOf(COSTED_LIMIT))->toBeGreaterThan(0)
        ->and($linesOf('Words a tokenizer would read as a line of text.'))->toBeGreaterThan(0);
});

it('guesses a unit by its lines where the plan measured the first run of others but none of its files', function () use (
    $linesOf,
): void {
    $root = Scratch::directory();
    Scratch::write($root, 'src/Other.php', COSTED_MONEY);
    $rates = SecondsPerLine::of(LineRate::of('', Seconds::of(0.5)));

    expect(MeasuredCosts::at(Root::of($root), $rates)->cost(Unit::file(Path::of('src/Other.php')), Timings::none(), measuredMoneyRun()))
        ->toEqual(Estimated::of(Seconds::of($linesOf(COSTED_MONEY) * 0.5), CostBasis::Guessed));
});

it('learns each unit\'s share of a shard\'s time from its mutants', function () use ($at): void {
    $mutant = static fn(string $file, float $seconds): Mutant => Mutant::of(
        MutantId::hash(Path::of($file), 'Plus', '-1', 0),
        '1',
        Location::of(Path::of($file), Line::of(1), Line::of(1)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, '-1'),
        MutantStatus::Killed,
        Seconds::of($seconds),
    );
    $units = Units::of(Unit::file(Path::of('src/Money.php')), Unit::file(Path::of('src/Limit.php')));
    $mutants = Mutants::of($mutant('src/Money.php', 3.0), $mutant('src/Limit.php', 1.0));
    $measured = Measurement::of(Seconds::of(40.0), 'pest', $at());
    $model = MeasuredCosts::at(Root::of('.'), SecondsPerLine::standard());
    $learned = $model->learn($units, $mutants, CoverageMap::empty(), $measured);

    expect($learned)->toEqual(Shares::of($units, $mutants, CoverageMap::empty(), $measured))
        ->and($learned->secondsFor(Path::of('src/Money.php')))->toEqual(Seconds::of(30.0));
});

it('reads the working directory at the seconds a line costs that costs.secondsPerLine gives it', function () use (
    $linesOf,
): void {
    $file = 'src/Core/Time/Unlimited.php';
    $lines = $linesOf((string) file_get_contents($file));
    $cost = static function (Options $options) use ($file): Seconds|Invalid {
        $model = MeasuredCosts::fromOptions($options);

        return $model instanceof MeasuredCosts ? $model->cost(Unit::file(Path::of($file)), Timings::none(), FirstRun::unmeasured())->seconds() : $model;
    };

    expect($lines)->toBeGreaterThan(0)
        ->and($cost(Shards::none()->costOptions()))->toEqual(Seconds::of($lines * 0.2))
        ->and($cost(Configs::options('{"secondsPerLine": {"": 2.0, "src/Core": 3}}')))->toEqual(Seconds::of($lines * 3.0))
        ->and($cost(Configs::options('{"secondsPerLine": {}}')))->toEqual(Seconds::of(0.0));
});

it('refuses seconds per line that are not a map of prefix to number, or not given', function (): void {
    expect(MeasuredCosts::fromOptions(Configs::options('{"secondsPerLine": {"src": "fast"}}')))
        ->toEqual(Invalid::because(Problem::at('secondsPerLine.src', 'expected a number, got "fast"')))
        ->and(MeasuredCosts::fromOptions(Configs::options('{"secondsPerLine": 0.2}')))
        ->toEqual(Invalid::because(Problem::at('secondsPerLine', 'expected an object, got 0.2')))
        ->and(MeasuredCosts::fromOptions(Options::none()))
        ->toEqual(Invalid::because(Problem::at('secondsPerLine', 'expected the seconds a line costs, got nothing')));
});

it('does not walk into a linked directory under a held one, which may lead back up into a loop', function () use ($linesOf): void {
    $root = Scratch::directory();
    Scratch::write($root, 'src/Kernel/Money.php', COSTED_MONEY);
    symlink('..', sprintf('%s/src/Kernel/loop', $root));
    $rates = SecondsPerLine::of(LineRate::of('', Seconds::of(1.0)));

    expect(MeasuredCosts::at(Root::of($root), $rates)->cost(Unit::held(Path::of('src/Kernel'), Group::named('holds:kernel')), Timings::none(), FirstRun::unmeasured()))
        ->toEqual(Estimated::of(Seconds::of($linesOf(COSTED_MONEY) * 1.0), CostBasis::Guessed));
});
