<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\SchemaSwitch;
use NightWorksIO\MutationGate\Adapter\Pest\Split\ChildLimit;
use NightWorksIO\MutationGate\Adapter\Pest\Split\ChildPlaces;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamPlan;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamRun;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    SchemaSwitch::unsplit();
    Scratch::sweep();
});

it('runs a pure expression\'s site with its original\'s value, and forks nothing, where no test of the stream is running', function (): void {
    $results = sprintf('%s/results-1-0.jsonl', Scratch::directory());
    StreamRun::of(new StreamPlan($results, ['money' => [3 => ['mutant-1', 'MoneyTest::adds', []]]], [], [], ChildPlaces::fixed(1), new ChildLimit(0.0, 0.0, 0.0)));

    $value = SchemaSwitch::value('money', 3, static fn(): string => 'original', static fn(): string => 'mutated');

    expect($value)->toBe('original')
        ->and(SchemaSwitch::is('money', 3))->toBeFalse()
        ->and(is_file($results))->toBeFalse();
});
