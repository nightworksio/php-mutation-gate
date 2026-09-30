<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistories;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistory;
use NightWorksIO\MutationGate\Core\Analysis\CheckTime;
use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\Analysis\Unchecked;
use NightWorksIO\MutationGate\Core\Analysis\UncheckedSurvivor;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Warning;

$left = static fn(Unchecked $why, string $file): UncheckedSurvivor => UncheckedSurvivor::of($why, Path::of($file));
$texts = static fn(SurvivorChecks $checks): array => array_map(
    static fn(Warning $warning): string => $warning->text(),
    [...$checks->warnings()],
);

it('warns once for each reason a survivor was left, in the order the reasons are declared, counting survivors and naming files once', function () use ($left, $texts): void {
    $checks = SurvivorChecks::none()
        ->leaving($left(Unchecked::OutOfTime, 'src/B.php'))
        ->leaving($left(Unchecked::OutOfScope, 'lib/Legacy.php'))
        ->leaving($left(Unchecked::OutOfTime, 'src/A.php'))
        ->leaving($left(Unchecked::OutOfTime, 'src/B.php'));

    expect($texts($checks))->toBe([
        'Static analysis left 1 survivor unchecked, as their files are outside the paths the analyser analyses: lib/Legacy.php.',
        'Static analysis left 3 survivors unchecked, as the time budget ran out before their checks: src/A.php, src/B.php.',
    ])
        ->and(array_map(static fn(UncheckedSurvivor $survivor): Unchecked => $survivor->why(), [...$checks]))
        ->toBe([Unchecked::OutOfTime, Unchecked::OutOfScope, Unchecked::OutOfTime, Unchecked::OutOfTime]);
});

it('names three files of a reason, sorted, and counts the rest', function () use ($left, $texts): void {
    $checks = SurvivorChecks::none();

    foreach (['src/E.php', 'src/C.php', 'src/A.php', 'src/D.php', 'src/B.php'] as $file) {
        $checks = $checks->leaving($left(Unchecked::Failed, $file));
    }

    expect($texts($checks))->toBe([
        'Static analysis left 5 survivors unchecked, as the analyser could not check them: src/A.php, src/B.php, src/C.php and 2 more.',
    ]);
});

it('names all three files of a reason with three', function () use ($left, $texts): void {
    $checks = SurvivorChecks::none()
        ->leaving($left(Unchecked::Failed, 'src/C.php'))
        ->leaving($left(Unchecked::Failed, 'src/A.php'))
        ->leaving($left(Unchecked::Failed, 'src/B.php'));

    expect($texts($checks))->toBe([
        'Static analysis left 3 survivors unchecked, as the analyser could not check them: src/A.php, src/B.php, src/C.php.',
    ]);
});

it('says why for every reason', function (Unchecked $why, string $because) use ($left, $texts): void {
    expect($texts(SurvivorChecks::none()->leaving($left($why, 'src/Money.php'))))
        ->toBe([sprintf('Static analysis left 1 survivor unchecked, as %s: src/Money.php.', $because)]);
})->with([
    'unidentified' => [Unchecked::Unidentified, 'the analyser could not say its version'],
    'no warm-up' => [Unchecked::NoWarmUp, 'the analyser\'s run over the original files failed'],
    'out of scope' => [Unchecked::OutOfScope, 'their files are outside the paths the analyser analyses'],
    'no mutant' => [Unchecked::NoMutant, 'the runner could not give their mutated code'],
    'print differs' => [Unchecked::PrintDiffers, 'their files analyse differently once printed as the runner prints its mutants'],
    'failed' => [Unchecked::Failed, 'the analyser could not check them'],
    'out of time' => [Unchecked::OutOfTime, 'the time budget ran out before their checks'],
]);

it('holds the time each analyser\'s checks took, and adds another shard\'s checks to its own', function () use ($left): void {
    $phpstan = AnalyserHistory::of('phpstan')->withTime(CheckTime::of(2, Seconds::of(1.0)));
    $first = SurvivorChecks::none()->timing(AnalyserHistory::of('phpstan'))->timing($phpstan)->leaving($left(Unchecked::Failed, 'src/A.php'));
    $second = SurvivorChecks::none()->timing($phpstan)->leaving($left(Unchecked::NoMutant, 'src/B.php'));
    $added = $first->plus($second);

    expect($first->histories())->toEqual(AnalyserHistories::none()->with($phpstan))
        ->and([...$added->histories()])->toEqual([AnalyserHistory::of('phpstan')->withTime(CheckTime::of(4, Seconds::of(2.0)))])
        ->and(array_map(static fn(UncheckedSurvivor $survivor): string => $survivor->file()->value(), [...$added]))
        ->toBe(['src/A.php', 'src/B.php'])
        ->and(SurvivorChecks::none()->warnings()->count())->toBe(0);
});
