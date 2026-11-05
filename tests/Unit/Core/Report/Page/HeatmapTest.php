<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Heat\Heatmap;
use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Report\Page\Heatmap as HeatmapCard;
use NightWorksIO\MutationGate\Core\Report\Page\ReportPage;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Heatmaps;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

$report = static function (Heatmap $heatmap): array {
    $verdict = Verdicts::failing();

    return JsonReport::of($verdict->withAccount($verdict->account()->withHeatmap($heatmap)));
};

it('shows every file folded in one table, lowest first, each function folded under its file', function () use ($report): void {
    $card = HeatmapCard::html($report(Heatmaps::fallen()));

    expect($card)->toContain('<h2 id="heatmap-title">Scores by file <span class="count">2</span></h2>')
        ->and($card)->toContain('<details><summary>Every file</summary>')
        ->and($card)->toContain('<tr><td><code>src/Cart.php</code></td><td>50.00%</td><td>±0.00</td><td>1</td></tr>')
        ->and($card)->toContain(
            '<tr><td><details><summary><code>src/Money.php</code></summary><ul class="heat-functions">'
            . '<li><code>Money::sub</code> 50.00% <span class="heat-change">-50.00</span> <span class="tag tag--escaped">1 not killed</span></li>'
            . '<li><code>Money::add</code> 100.00% <span class="heat-change">±0.00</span></li>'
            . '</ul></details></td><td>50.00%</td><td>-25.00</td><td>2</td></tr>',
        )
        ->and(explode('src/Cart.php', $card, 2)[0])->not->toContain('src/Money.php');
});

it('shows no change where the base has no score, says a file with nothing to mutate has none, and shows no card where it scored no file', function () use ($report): void {
    $unscored = Heatmap::of(Heatmaps::tree(80, [['src/Cart.php:3', MutantJudgement::Ignored, false]]), Heatmaps::spans(...));

    expect(HeatmapCard::html($report($unscored)))->toContain('<tr><td><code>src/Cart.php</code></td><td>nothing to mutate</td><td></td><td>0</td></tr>')
        ->and(HeatmapCard::html($report(Heatmap::none())))->toBe('')
        ->and(ReportPage::html($report(Heatmaps::fallen()), '', ''))->toContain('id="heatmap-title"');
});
