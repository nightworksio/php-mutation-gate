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
    'merged deltas' => [['reuse' => ['delta' => true]], ['reuse.delta: true']],
    'forks' => [['reuse' => ['forks' => true]], ['reuse.forks: true']],
    'merged own-scope proofs' => [['reuse' => ['ownScopeProofs' => 'trust']], ['reuse.ownScopeProofs: trust']],
    'all three, reusing on pull requests alone' => [
        ['reuse' => ['identical' => 'pulls', 'delta' => true, 'forks' => true, 'ownScopeProofs' => 'trust']],
        ['reuse.forks: true'],
    ],
    'all three, reusing nothing' => [
        ['reuse' => ['identical' => 'off', 'delta' => true, 'forks' => true, 'ownScopeProofs' => 'trust']],
        [],
    ],
    'a lock move that loads' => [['reach' => ['lockMoves' => 'loaded']], ['reach.lockMoves: loaded']],
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

    $reused = static fn(string $key): string => [...LooseValues::in(Configs::settings(['runner' => 'pest', 'reuse' => [$key => true]]))][0]->noted();

    expect($loose[0]->noted())->toBe('controls.kills is false, a loose value: a kill counts without its control on the unmutated code')
        ->and($reused('delta'))->toEndWith('a merged pull request whose landed tree differs carries what the change from its judged head does not reach')
        ->and($reused('forks'))->toEndWith('a verdict or results a fork\'s run recorded are reused');
});

it('counts the loose values in force', function (): void {
    expect(LooseValues::in(Configs::settings(['runner' => 'pest', 'controls' => ['kills' => false], 'reach' => ['lockMoves' => 'loaded']])))
        ->toHaveCount(2);
});
