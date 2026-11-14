<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\WorkerCount;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Local\WorkerShare;

$read = static fn(string $json): Node => Node::config($json)->field('workers');

it('names a whole count and every share of the cores it takes', function (): void {
    expect(new WorkerCount()->expected())->toBe('an integer of at least 1, or one of "half", "all-but-one"');
});

it('reads a share of the cores or a whole count, and refuses anything else by what it takes', function () use ($read): void {
    $workers = new WorkerCount();

    expect($workers->read($read('{"workers": "half"}'))->value())->toBe(WorkerShare::Half)
        ->and($workers->read($read('{"workers": 3}'))->value())->toBe(3)
        ->and($workers->read($read('{"workers": "all"}'))->problems())
        ->toEqual([Problem::at('workers', 'expected an integer of at least 1, or one of "half", "all-but-one", got "all"')]);
});
