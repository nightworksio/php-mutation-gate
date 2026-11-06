<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\HeatmapRows;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Heat\FunctionSpans;
use NightWorksIO\MutationGate\Core\Heat\Heatmap;
use NightWorksIO\MutationGate\Core\Report\HeatmapMarkdown;
use NightWorksIO\MutationGate\Core\Report\Markdown;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Heatmaps;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('tables the files that fell, each with its change and its lowest functions inline, and nothing for none', function (): void {
    expect(HeatmapMarkdown::table(Heatmaps::fallen()->fallen()))->toBe([implode("\n", [
        '| File | Score | Against the base | Lowest functions |',
        '|---|---|---|---|',
        '| <code>src/Money.php</code> | 50.00% | -25.00 | <code>Money::sub</code> 50.00% (-50.00), <code>Money::add</code> 100.00% (±0.00) |',
        '| <code>src/Cart.php</code> | 50.00% | ±0.00 |  |',
    ])])
        ->and(HeatmapMarkdown::table(Listed::of()))->toBe([]);
});

it('names at most three functions of a file, and a function the base has no score for without a change', function (): void {
    $source = "<?php\nfunction a() {\n}\nfunction b() {\n}\nfunction c() {\n}\nfunction d() {\n}\n";
    $at = static fn(MutantJudgement $last): array => [
        ['src/Many.php:2', MutantJudgement::Killed, true],
        ['src/Many.php:4', MutantJudgement::Killed, false],
        ['src/Many.php:6', MutantJudgement::Killed, false],
        ['src/Many.php:8', $last, false],
    ];
    $spans = static fn(): FunctionSpans => FunctionSpans::in(Contents::of($source));
    $base = Heatmap::of(Heatmaps::tree(0, $at(MutantJudgement::Killed)), static fn(): FunctionSpans => FunctionSpans::none());
    $fallen = Heatmap::of(Heatmaps::tree(0, $at(MutantJudgement::Survived)), $spans)->comparedWith($base)->listing(HeatmapRows::of(files: 0, comment: 1))->fallen();

    expect(HeatmapMarkdown::table($fallen)[0])
        ->toEndWith('| <code>src/Many.php</code> | 75.00% | -25.00 | <code>d</code> 0.00%, <code>a</code> 100.00%, <code>b</code> 100.00% |');
});

it('puts the files that fell after the trees in the pull request comment, and leaves the section out where none fell', function (): void {
    $verdict = Verdicts::failing();
    $comment = Markdown::comment($verdict->withAccount($verdict->account()->withHeatmap(Heatmaps::fallen())));

    expect($comment)->toContain("### Files that fell, or sit below their floor (2)\n\n| File | Score | Against the base | Lowest functions |")
        ->and(explode('### Files that fell', $comment, 2)[0])->toContain('| Tree |')
        ->and(Markdown::comment($verdict))->not->toContain("Files that fell");
});
