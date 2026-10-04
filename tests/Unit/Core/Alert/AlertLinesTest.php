<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Alert\Alert;
use NightWorksIO\MutationGate\Core\Alert\AlertEvent;
use NightWorksIO\MutationGate\Core\Alert\AlertLines;
use NightWorksIO\MutationGate\Core\Alert\Chat;
use NightWorksIO\MutationGate\Core\Baseline\Lowered;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Report\TrendEntry;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

$was = TrendEntry::none()->withScore(Path::of('src'), Score::ofHundredths(8_100))->withFloor(Path::of('src'), Floor::of(90));

it('says of a failure each tree below its floor, the failures no floor decides, and the first survivors', function () use ($was): void {
    expect(AlertLines::of(Alert::of(AlertEvent::Failed, Verdicts::failing(), $was), Chat::Slack))->toBe([
        ['Below the floor', ['`src`: 44.44%, below its floor of 80.00%; it was 81.00%.']],
        ['Failures', ['The ignore of 3f9a1c2b7d04 matched no mutant. Remove it.']],
        ['Survivors', [
            '`src/Money.php:7` LessToLessOrEqual, survived',
            '`src/Money.php:12` FalseValue, uncovered',
            '`src/Order.php:3` MethodCallRemoval, flaky',
            '`src/Order.php:5` DecrementInteger, unjudged',
            '`src/Order.php:8` Plus, too slow to judge',
        ]],
    ]);
});

it('names at most five survivors, then how many more there are, and leaves out a group with nothing in it', function (): void {
    $survivors = [];

    foreach (range(1, 7) as $line) {
        $survivors[] = JudgedMutant::of(Verdicts::mutant(sprintf('src/A.php:%d', $line), 'Plus', MutatorFamily::Arithmetic, Verdicts::diff(sprintf('$a + %d;', $line), sprintf('$a - %d;', $line))), MutantJudgement::Survived);
    }

    $verdict = Verdicts::of(Floor::of(90), ...$survivors);
    [[$heading, $lines], [$survivorHeading, $named]] = AlertLines::of(Alert::of(AlertEvent::Failed, $verdict, TrendEntry::none()), Chat::Discord);

    expect($heading)->toBe('Below the floor')
        ->and($lines)->toBe(['`src`: 0.00%, below its floor of 90.00%.'])
        ->and($survivorHeading)->toBe('Survivors')
        ->and($named)->toHaveCount(6)
        ->and($named[5])->toBe('And 2 more.');
});

it('says why the run cannot judge', function (): void {
    $verdict = Verdicts::passing()->withCannotJudge(CannotJudge::because('Shard 2 wrote no result, so <its> units are unjudged.'));

    expect(AlertLines::of(Alert::of(AlertEvent::CannotJudge, $verdict, TrendEntry::none()), Chat::Slack))
        ->toBe([['Why', ['Shard 2 wrote no result, so &lt;its&gt; units are unjudged.']]]);
});

it('says of a recovery each passing tree against its floor', function () use ($was): void {
    expect(AlertLines::of(Alert::of(AlertEvent::Recovered, Verdicts::passing(), $was), Chat::Slack))
        ->toBe([['Trees', ['`src`: 100.00% against its floor of 80.00%; it was 81.00%.']]]);
});

it('says of each lowered floor where it went, and why, where the baseline says', function (): void {
    $tree = static fn(string $path): TreeVerdict => TreeVerdict::judged(
        Tree::at(Path::of($path), Floor::of(70), Package::at(Path::root())),
        Unrecorded::floor(),
        JudgedUnits::none(),
        JudgedMutants::none(),
        Uncovered::Count,
    );
    $verdict = Verdict::of(TreeVerdicts::of(
        $tree('app')->withLowering(Lowered::from(Floor::of(90), sprintf('Legacy code joined the tree%s', str_repeat('!', 200)))),
        $tree('lib'),
    ));
    $entry = TrendEntry::none()->withFloor(Path::of('app'), Floor::of(90))->withFloor(Path::of('lib'), Floor::of(75));

    expect(AlertLines::of(Alert::of(AlertEvent::FloorLowered, $verdict, $entry), Chat::Slack))->toBe([['Floors lowered', [
        sprintf('`app`: its floor went from 90.00%% to 70.00%%. Legacy code joined the tree%s…', str_repeat('!', 92)),
        '`lib`: its floor went from 75.00% to 70.00%. The baseline gives no reason.',
    ]]]);
});

it('names each security set below its floor when a run fails', function () use ($was): void {
    $groups = AlertLines::of(Alert::of(AlertEvent::Failed, Verdicts::secured(), $was), Chat::Slack);

    expect($groups[1] ?? null)->toBe(['Security below its floor', ['Security: 0.00%, below its floor of 100.00%.']]);
});
