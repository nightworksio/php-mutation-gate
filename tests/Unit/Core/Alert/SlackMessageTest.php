<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Alert\Alert;
use NightWorksIO\MutationGate\Core\Alert\AlertEvent;
use NightWorksIO\MutationGate\Core\Alert\SlackMessage;
use NightWorksIO\MutationGate\Core\Ci\CiRun;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Report\TrendEntry;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
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
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Previous;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('sends the title as the fallback, then a header, a section per group and the run', function (): void {
    $message = SlackMessage::json(Alert::of(AlertEvent::Recovered, Verdicts::passing(), TrendEntry::none()), Previous::ci());

    expect(Decoded::at($message))->toBe([
        'text' => 'mutation-gate: recovered on octo/gate main',
        'blocks' => [
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => 'mutation-gate: recovered on octo/gate main']],
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "*Trees*\n`src`: 100.00% against its floor of 80.00%."]],
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '<https://github.example/octo/gate/actions/runs/7|The run>']],
        ],
    ]);
});

it('keeps every block within what Slack takes, however long the names', function (): void {
    $long = str_repeat('a', 400);
    $run = CiRun::of(sprintf('octo/%s', $long), sprintf('refs/heads/%s', $long), 'c', 'https://ci.example/7');
    $message = SlackMessage::json(Alert::of(AlertEvent::Failed, Verdicts::crowded(), TrendEntry::none()), $run);
    $blocks = Decoded::at($message, 'blocks');
    $lengths = [];

    foreach (is_array($blocks) ? $blocks : [] as $block) {
        $text = is_array($block) && is_array($block['text']) ? $block['text']['text'] : '';
        $lengths[] = is_string($text) ? mb_strlen($text) : 0;
    }

    expect($lengths)->toHaveCount(4)
        ->and($lengths[0])->toBe(150)
        ->and(max(0, ...$lengths))->toBeLessThanOrEqual(3_000)
        ->and($lengths[1])->toBeGreaterThan(2_800)
        ->and(Decoded::at($message, 'blocks', 1, 'text', 'text'))->toEndWith(' more.');
});

it('keeps a section of exactly Slack\'s three thousand characters whole, and cuts one a character past it', function (): void {
    $text = static function (int $last): string {
        $trees = [];

        foreach (range(1, 22) as $each) {
            $path = $each === 22 ? str_repeat('b', $last) : sprintf('%s%d', str_repeat('a', 100), $each);
            $trees[] = TreeVerdict::judged(
                Tree::at(Path::of($path), Floor::of(90), Package::at(Path::root())),
                Unrecorded::floor(),
                JudgedUnits::none(),
                JudgedMutants::of(JudgedMutant::of(
                    Verdicts::mutant(sprintf('%s/A.php:1', $path), 'Plus', MutatorFamily::Arithmetic, ''),
                    MutantJudgement::Survived,
                )),
                Uncovered::Count,
            );
        }

        $said = Decoded::at(SlackMessage::json(Alert::of(AlertEvent::Failed, Verdict::of(TreeVerdicts::of(...$trees)), TrendEntry::none()), Previous::ci()), 'blocks', 1, 'text', 'text');

        return is_string($said) ? $said : '';
    };
    $fits = 3_000 - (mb_strlen($text(1)) - 1);

    expect(mb_strlen($text($fits)))->toBe(3_000)
        ->and(mb_strlen($text($fits + 1)))->toBeLessThan(3_000)
        ->and($text($fits + 1))->toMatch('/And \\d+ more\\.$/');
});
