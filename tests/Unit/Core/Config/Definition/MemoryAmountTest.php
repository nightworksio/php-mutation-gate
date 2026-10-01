<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\MemoryAmount;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;

it('reads an amount of memory as PHP writes it, or -1 for none', function (): void {
    expect(MemoryAmount::written()->read(Node::config('"2g"'))->value())->toEqual(MemoryCap::of(2, MemoryUnit::Gigabytes))
        ->and(MemoryAmount::written()->read(Node::config('"-1"'))->value())->toEqual(MemoryCap::none());
});

it('refuses what is not an amount of memory, text or not', function (string $written, string $got): void {
    expect(MemoryAmount::written()->read(Node::config($written))->problems())->toEqual([
        Problem::at('', sprintf('expected an amount of memory such as 512M or 1G, or -1 for none, got %s', $got)),
    ]);
})->with([
    'a unit PHP does not take' => ['"512MB"', '"512MB"'],
    'a number' => ['512', '512'],
]);

it('writes a pattern of what PHP takes, and has no effect of its own', function (): void {
    expect(MemoryAmount::written()->schema())
        ->toEqual(Json::parse('{"type": "string", "pattern": "^(?:-1|[1-9][0-9]*[KkMmGg]?)$"}'))
        ->and(MemoryAmount::written()->effects())->toBe([]);
});
