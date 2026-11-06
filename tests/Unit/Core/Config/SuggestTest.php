<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Config\Runner as ConfiguredRunner;
use NightWorksIO\MutationGate\Config\Suggest as SuggestBuilder;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Reports;
use NightWorksIO\MutationGate\Core\Config\Suggest;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('suggests by default from 500 judged mutants, no survivors and 5% of the time, 80% of 50 mutants ignored, and 3 timeouts', function (): void {
    $suggest = Layer::standard()->reports()->suggest();

    expect([$suggest->idleJudged(), $suggest->idleSurvivors(), $suggest->ignoredMutants(), $suggest->timeoutCluster()])->toBe([500, 0, 50, 3])
        ->and([$suggest->idleShare(), $suggest->ignoredShare()])->toEqual([Percentage::parse(5), Percentage::parse(80)]);
});

it('reads every suggest key a config writes, the rest at their defaults, and lays a later layer\'s over an earlier one\'s, key by key', function (): void {
    $read = Configs::valid(['suggest' => [
        'idle' => ['judged' => 100, 'survivors' => 1, 'share' => 2.5],
        'ignored' => ['share' => 90, 'mutants' => 10],
        'timeouts' => ['cluster' => 5],
    ]])->reports()->suggest();
    $laid = Layer::of(Reports::suggesting(Suggest::of(idleJudged: 100, timeoutCluster: 4)))
        ->over(Layer::of(Reports::suggesting(Suggest::of(timeoutCluster: 6))))
        ->reports()
        ->suggest();

    $alone = Configs::valid(['suggest' => ['timeouts' => ['cluster' => 6]]])->reports()->suggest();

    expect([$read->idleJudged(), $read->idleSurvivors(), $read->ignoredMutants(), $read->timeoutCluster()])->toBe([100, 1, 10, 5])
        ->and([$alone->idleJudged(), $alone->ignoredMutants(), $alone->timeoutCluster()])->toBe([500, 50, 6])
        ->and([$read->idleShare(), $read->ignoredShare()])->toEqual([Percentage::parse(2.5), Percentage::parse(90)])
        ->and([$laid->idleJudged(), $laid->timeoutCluster()])->toBe([100, 6]);
});

it('refuses a suggest key out of its range', function (array $suggest, string $at): void {
    $problems = Configs::problems(Configs::layer(['suggest' => $suggest]));

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toStartWith(sprintf('%s: ', $at));
})->with([
    'no judged mutants' => [['idle' => ['judged' => 0]], 'suggest.idle.judged'],
    'fewer than no survivors' => [['idle' => ['survivors' => -1]], 'suggest.idle.survivors'],
    'a share past 100' => [['ignored' => ['share' => 101]], 'suggest.ignored.share'],
    'no timeouts' => [['timeouts' => ['cluster' => 0]], 'suggest.timeouts.cluster'],
]);

it('writes only the suggest keys a layer sets, and the builder calls for them, and the builder writes the same keys', function (): void {
    $layer = Layer::of(Reports::suggesting(Suggest::of(idleShare: 7, timeoutCluster: 4)));
    $built = Configs::written(Gate::configure()->with(SuggestBuilder::idleShare(7))->with(SuggestBuilder::timeoutCluster(4)));

    expect($layer->written(ProjectRoot::origin())->line())->toBe('{"suggest":{"idle":{"share":7},"timeouts":{"cluster":4}}}')
        ->and($layer->php(ProjectRoot::origin())->code())->toContain('Suggest::idleShare(7)')
        ->and($layer->php(ProjectRoot::origin())->code())->toContain('Suggest::timeoutCluster(4)')
        ->and($built)->toBe(['suggest' => ['idle' => ['share' => 7], 'timeouts' => ['cluster' => 4]]])
        ->and(Layer::of(Reports::suggesting(Suggest::of()))->php(ProjectRoot::origin())->code())->not->toContain('Suggest::');
});

it('builds every suggest key from the PHP builder', function (): void {
    $settings = Configs::built(Gate::configure()
        ->runner(ConfiguredRunner::uses('fake'))
        ->with(SuggestBuilder::idleJudged(10))
        ->with(SuggestBuilder::idleSurvivors(2))
        ->with(SuggestBuilder::idleShare(1))
        ->with(SuggestBuilder::ignoredShare(70))
        ->with(SuggestBuilder::ignoredMutants(5))
        ->with(SuggestBuilder::timeoutCluster(9)));
    $suggest = $settings->effective()->reports()->suggest();

    expect([$suggest->idleJudged(), $suggest->idleSurvivors(), $suggest->ignoredMutants(), $suggest->timeoutCluster()])->toBe([10, 2, 5, 9])
        ->and([$suggest->idleShare(), $suggest->ignoredShare()])->toEqual([Percentage::parse(1), Percentage::parse(70)]);
});
