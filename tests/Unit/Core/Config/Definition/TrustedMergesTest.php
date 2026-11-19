<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\TrustedMerges;
use NightWorksIO\MutationGate\Core\Config\MergedTrust;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\Node;

$read = static fn(string $json): Node => Node::config($json)->field('trust');

it('names the three values it takes', function (): void {
    expect(TrustedMerges::values()->expected())->toBe('true, false or "delta"');
});

it('reads true, false and "delta", and refuses any other value by what it takes', function () use ($read): void {
    $trust = TrustedMerges::values();

    expect($trust->read($read('{"trust": true}'))->value())->toBe(MergedTrust::Tree)
        ->and($trust->read($read('{"trust": false}'))->value())->toBe(MergedTrust::Off)
        ->and($trust->read($read('{"trust": "delta"}'))->value())->toBe(MergedTrust::Delta)
        ->and($trust->read($read('{"trust": "tree"}'))->problems())
        ->toEqual([Problem::at('trust', 'expected true, false or "delta", got "tree"')]);
});
