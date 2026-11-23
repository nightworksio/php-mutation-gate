<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\LooseValue;
use NightWorksIO\MutationGate\Core\Config\LooseValues;
use NightWorksIO\MutationGate\Tests\Support\Configs;

/**
 * The keys and values of the loose values a config holds in force.
 *
 * @param  array<mixed> $config
 * @return list<string>
 */
function looseIn(array $config): array
{
    return array_map(
        static fn(LooseValue $loose): string => sprintf('%s: %s', $loose->key(), $loose->value()),
        [...LooseValues::in(Configs::settings(['runner' => 'pest', ...$config]))],
    );
}

it('holds no loose value under the defaults', function (): void {
    expect(looseIn([]))->toBe([]);
});

it('names each loose value in force', function (array $config, array $loose): void {
    expect(looseIn($config))->toBe($loose);
})->with([
    'kills uncontrolled' => [['controls' => ['kills' => false]], ['controls.kills: false']],
    'narrowed kills trusted' => [['pest' => ['narrowedKills' => 'trust']], ['pest.narrowedKills: trust']],
    'merged pull requests trusted' => [['ci' => ['trustMergedPullRequests' => true]], ['ci.trustMergedPullRequests: true']],
    'their deltas' => [['ci' => ['trustMergedPullRequests' => 'delta']], ['ci.trustMergedPullRequests: delta']],
    'their own-scope proofs, with them' => [
        ['ci' => ['trustMergedPullRequests' => true, 'mergedOwnScopeProofs' => 'trust']],
        ['ci.trustMergedPullRequests: true', 'ci.mergedOwnScopeProofs: trust'],
    ],
    'their own-scope proofs, without them' => [['ci' => ['mergedOwnScopeProofs' => 'trust']], []],
    'a lock move that loads' => [['reach' => ['lockMoves' => 'loaded']], ['reach.lockMoves: loaded']],
    'a tree held to 100 sampled' => [['sampling' => ['top' => true]], ['sampling.top: true']],
    'a tree held to 100 kept whole, as by default' => [['sampling' => ['top' => false]], []],
    'a boot once in streams' => [['splitStream' => ['enabled' => true, 'bootOnce' => true]], ['splitStream.bootOnce: true']],
    'a boot once with no stream' => [['splitStream' => ['enabled' => false, 'bootOnce' => true]], []],
    'a reconnect in streams, as by default' => [['splitStream' => ['enabled' => true, 'reconnect' => true]], []],
]);

it('names trials off and held coverage from the map only where uncovered mutants are excluded', function (): void {
    $config = ['pest' => ['trials' => false], 'holds' => ['coverage' => 'map']];

    expect(looseIn($config))->toBe([])
        ->and(looseIn([...$config, 'uncovered' => 'exclude']))->toBe(['pest.trials: false', 'holds.coverage: map']);
});

it('says what each lets count or carry', function (): void {
    $loose = [...LooseValues::in(Configs::settings(['runner' => 'pest', 'controls' => ['kills' => false]]))];

    $merged = static fn(bool|string $trust): string => [...LooseValues::in(Configs::settings(['runner' => 'pest', 'ci' => ['trustMergedPullRequests' => $trust]]))][0]->noted();

    expect($loose[0]->noted())->toBe('controls.kills is false, a loose value: a kill counts without its control on the unmutated code')
        ->and($merged(trust: 'delta'))->toEndWith('a merged pull request whose landed tree differs carries what the change from its judged head does not reach')
        ->and($merged(trust: true))->toEndWith('a pushed tree a merged pull request\'s verdict passed on is not judged again');
});

it('counts the loose values in force', function (): void {
    expect(LooseValues::in(Configs::settings(['runner' => 'pest', 'controls' => ['kills' => false], 'reach' => ['lockMoves' => 'loaded']])))
        ->toHaveCount(2);
});
