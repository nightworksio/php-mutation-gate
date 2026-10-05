<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hint\Hint;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Report\MutantText;
use NightWorksIO\MutationGate\Core\Report\Problems;
use NightWorksIO\MutationGate\Core\Report\ProblemsShown;
use NightWorksIO\MutationGate\Core\Report\Sources;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnit;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Tests\Support\Judged;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

$sources = static fn(): Sources => Sources::none()->with(Path::of('src/Money.php'), Contents::of(Verdicts::MONEY));

$line = static fn(JudgedMutant $judged, string $place, string $level, string $mark, string $rule): string => sprintf(
    "%s: %s: %s%s [%s] %s\n",
    $place,
    $level,
    MutantText::message($judged),
    $mark,
    $rule,
    $judged->mutant()->id()->value(),
);

it('prints a line per result, at its column, with the run a proved or carried one came from', function () use ($sources, $line): void {
    $mutants = Judged::listed(Verdicts::everyJudgement());

    expect(Problems::text(Verdicts::failing(), $sources(), ProblemsShown::All))->toBe(implode('', [
        $line($mutants[0], 'src/Money.php:7:21', 'error', '', 'survived'),
        $line($mutants[2], 'src/Money.php:12:1', 'error', '', 'uncovered'),
        $line($mutants[3], 'src/Order.php:3:1', 'error', ' (proved)', 'flaky'),
        $line($mutants[4], 'src/Order.php:5:1', 'error', ' (proved)', 'unjudged'),
        $line($mutants[5], 'src/Order.php:8:1', 'error', ' (proved)', 'unjudged'),
    ]));
});

it('prints only the results on changed lines, when asked', function () use ($sources, $line): void {
    expect(Problems::text(Verdicts::failing(), $sources(), ProblemsShown::Changed))
        ->toBe($line(Verdicts::survivor(), 'src/Money.php:7:21', 'error', '', 'survived'));
});

it('names the run whose proof a proved or carried result came from, and warns of one in a set that passed', function () use ($line): void {
    $run = Run::of('github:12345/1', Instant::at(new DateTimeImmutable('2026-09-30T10:00:00Z')), Digest::sha256Of('base'));
    $proved = Verdicts::mutant('src/Order.php:3', 'Plus', MutatorFamily::Arithmetic, Verdicts::diff('$a + $b;', '$a - $b;'));
    $carried = Verdicts::mutant('src/Log.php:4', 'Plus', MutatorFamily::Arithmetic, Verdicts::diff('$c + $d;', '$c - $d;'));
    $mutants = JudgedMutants::of(JudgedMutant::of($proved, MutantJudgement::Survived), JudgedMutant::of($carried, MutantJudgement::Survived));
    $units = JudgedUnits::of(
        JudgedUnit::of(Unit::file(Path::of('src/Order.php')), Origin::Proved)->withRun($run),
        JudgedUnit::of(Unit::file(Path::of('src/Log.php')), Origin::Carried)->withRun($run),
    );
    $tree = TreeVerdict::judged(Tree::at(Path::of('src'), Floor::of(0), Package::at(Path::root())), Unrecorded::floor(), $units, $mutants, Uncovered::Count);
    $all = Judged::listed($mutants);

    expect(Problems::text(Verdict::of(TreeVerdicts::of($tree)), Sources::none(), ProblemsShown::All))->toBe(implode('', [
        $line($all[0], 'src/Order.php:3:1', 'warning', ' (proved in run github:12345/1)', 'survived'),
        $line($all[1], 'src/Log.php:4:1', 'warning', ' (carried from run github:12345/1)', 'survived'),
    ]));
});

it('keeps each result on one line, whatever its hint holds, and marks none a verdict lists no unit for', function (): void {
    $survivor = Verdicts::survivor()->hinted(Hint::that("No test\r\nuses\rthe\nboundary."));

    expect(Problems::text(Verdicts::of(Floor::of(0), $survivor), Sources::none(), ProblemsShown::All))->toBe(sprintf(
        "src/Money.php:7:1: warning: Mutant survived: LessToLessOrEqual. No test uses the boundary. Reproduce: %s [survived] %s\n",
        $survivor->reproduce(),
        $survivor->mutant()->id()->value(),
    ));
});

it('prints nothing for a verdict with no result', function (string $verdict): void {
    expect(Problems::text(Verdicts::named($verdict), Sources::none(), ProblemsShown::All))->toBe('');
})->with(['passing', 'empty']);

it('frames each judgement for a background matcher', function (): void {
    expect([Problems::JUDGING, Problems::JUDGED])->toBe(['mutation-gate: judging', 'mutation-gate: judged'])
        ->and(array_map(static fn(ProblemsShown $shown): string => $shown->value, ProblemsShown::cases()))->toBe(['all', 'changed']);
});

it('writes no escape or other control character a project\'s code could carry into an editor', function (): void {
    $survivor = Verdicts::survivor()->hinted(Hint::that("No test\e[31m uses\tthe\x07 boundary."));

    expect(Problems::text(Verdicts::of(Floor::of(0), $survivor), Sources::none(), ProblemsShown::All))->toContain('. No test[31m uses the boundary. Reproduce: ')
        ->and(Problems::text(Verdicts::of(Floor::of(0), $survivor), Sources::none(), ProblemsShown::All))->not->toMatch('/\p{Cc}(?!$)/u');
});

it('forges no line for a problem matcher from a path or a run id that holds a line break', function (): void {
    $forged = "\nsrc/Evil.php:1:1: error: forged [survived] 0123456789ab\n";
    $file = Path::of(sprintf('src/Money%s.php', $forged));
    $diff = Verdicts::diff('$a + $b;', '$a - $b;');
    $mutant = Mutant::of(
        MutantId::hash($file, 'Plus', $diff, 7),
        'native-7',
        Location::of($file, Line::of(7), Line::of(7)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, $diff),
        MutantStatus::Survived,
        Unmeasured::duration(),
    );
    $run = Run::of(sprintf('github:1%s', $forged), Instant::at(new DateTimeImmutable('2026-09-30T10:00:00Z')), Digest::sha256Of('base'));
    $tree = TreeVerdict::judged(
        Tree::at(Path::of('src'), Floor::of(0), Package::at(Path::root())),
        Unrecorded::floor(),
        JudgedUnits::of(JudgedUnit::of(Unit::file($file), Origin::Proved)->withRun($run)),
        JudgedMutants::of(JudgedMutant::of($mutant, MutantJudgement::Survived)),
        Uncovered::Count,
    );
    $text = Problems::text(Verdict::of(TreeVerdicts::of($tree)), Sources::none(), ProblemsShown::All);

    expect(substr_count($text, "\n"))->toBe(1)
        ->and($text)->toStartWith('src/Moneysrc/Evil.php:1:1: error: forged [survived] 0123456789ab.php:7:1: warning: ')
        ->and($text)->toContain(' (proved in run github:1src/Evil.php:1:1: error: forged [survived] 0123456789ab) [survived] ');
});
