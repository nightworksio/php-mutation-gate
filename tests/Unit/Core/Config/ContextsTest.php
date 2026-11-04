<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Context;
use NightWorksIO\MutationGate\Core\Config\ContextChain;
use NightWorksIO\MutationGate\Core\Config\Contexts;
use NightWorksIO\MutationGate\Core\Config\Local;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('lays a later layer\'s block over an earlier one\'s of the same context, and keeps the others', function (): void {
    $earlier = Configs::valid(['contexts' => [
        'pr' => ['budget' => '5m', 'timeouts' => ['seconds' => 30]],
        'local' => ['budget' => '1m'],
    ]]);
    $later = Configs::valid(['contexts' => ['pr' => ['timeouts' => ['seconds' => 60]], 'main' => ['budget' => '1h']]]);

    expect(Configs::decoded($earlier->over($later)))->toBe(['contexts' => [
        'local' => ['budget' => '1m'],
        'pr' => ['budget' => '5m', 'timeouts' => ['seconds' => 60]],
        'main' => ['budget' => '1h'],
    ]]);
});

it('lays each context\'s block over the rest of a layer in order, and leaves no block', function (
    ContextChain $chain,
    array $laid,
): void {
    $layer = Configs::valid([
        'budget' => '30m',
        'shards' => ['max' => 8],
        'contexts' => [
            'local' => ['budget' => '2m'],
            'ci' => ['shards' => ['max' => 4], 'budget' => '20m'],
            'pr' => ['budget' => '10m'],
            'main' => ['shards' => ['max' => 16]],
        ],
    ]);

    expect(Configs::decoded($layer->in($chain)))->toBe($laid);
})->with([
    'no context' => [fn(): ContextChain => ContextChain::none(), ['shards' => ['max' => 8], 'budget' => '30m']],
    'local' => [fn(): ContextChain => Context::Local->chain(), ['shards' => ['max' => 8], 'budget' => '2m']],
    'a push in CI' => [fn(): ContextChain => Context::Ci->chain(), ['shards' => ['max' => 4], 'budget' => '20m']],
    'a pull request, over ci' => [fn(): ContextChain => Context::PullRequest->chain(), ['shards' => ['max' => 4], 'budget' => '10m']],
    'the default branch, over ci' => [fn(): ContextChain => Context::Main->chain(), ['shards' => ['max' => 16], 'budget' => '20m']],
]);

it('lays nothing for a context a layer writes no block for', function (): void {
    expect(Configs::decoded(Contexts::none()->block(Context::Main)))->toBe([])
        ->and(Configs::decoded(Configs::valid(['budget' => '5m'])->in(Context::PullRequest->chain())))
        ->toBe(['budget' => '5m']);
});

it('writes each block as the PHP builder\'s Context, its calls nested a level deeper', function (): void {
    $layer = Configs::valid(['contexts' => [
        'pr' => ['runner' => 'pest', 'shards' => ['max' => 4]],
        'local' => ['budget' => '2m'],
    ]]);

    expect($layer->php(ProjectRoot::origin())->code())->toBe(<<<'PHP'

            ->with(
                Context::local(Gate::configure()
                    ->with(Budget::of('2m'))),
                Context::pullRequest(Gate::configure()
                    ->runner(Runner::pest())
                    ->with(Shards::max(4))),
            )
        PHP);
});

it('spells a chain of contexts by their keys, and every context\'s key for a message', function (): void {
    expect(Context::Main->chain()->spelt())->toBe('ci, main')
        ->and(ContextChain::none()->spelt())->toBe('')
        ->and(Context::words())->toBe('local, ci, pr or main')
        ->and(array_map(static fn(Context $context): string => $context->builder(), Context::cases()))
        ->toBe(['local', 'ci', 'pullRequest', 'main']);
});

it('lays nothing of a part that holds no blocks', function (): void {
    $blocks = Contexts::of(Context::Ci, Configs::valid(['budget' => '1m']));

    expect($blocks->over(Local::none()))->toBe($blocks);
});
