<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamSettings;

afterEach(function (): void {
    foreach ([GateVariable::Split, GateVariable::SplitChildren, GateVariable::SplitChangeCheck, GateVariable::SplitKillersFirst] as $variable) {
        putenv($variable->value);
    }
});

it('tells a run nothing where it does not split its streams', function (): void {
    expect(StreamSettings::off()->variables())->toBe([])
        ->and(StreamSettings::of(false, 3, true, true)->variables())->toBe([]);
});

it('tells a run that splits its streams how, at least one child at once', function (): void {
    expect(StreamSettings::of(true, 3, false, true)->variables())->toBe([
        'MUTATION_GATE_SPLIT' => '1',
        'MUTATION_GATE_SPLIT_CHILDREN' => '3',
        'MUTATION_GATE_SPLIT_CHANGE_CHECK' => '0',
        'MUTATION_GATE_SPLIT_KILLERS_FIRST' => '1',
    ])->and(StreamSettings::of(true, 0, true, false)->variables())->toBe([
        'MUTATION_GATE_SPLIT' => '1',
        'MUTATION_GATE_SPLIT_CHILDREN' => '1',
        'MUTATION_GATE_SPLIT_CHANGE_CHECK' => '1',
        'MUTATION_GATE_SPLIT_KILLERS_FIRST' => '0',
    ]);
});

it('reads back what it told a run', function (): void {
    foreach (StreamSettings::of(true, 4, false, false)->variables() as $name => $value) {
        putenv(sprintf('%s=%s', $name, $value));
    }

    $read = StreamSettings::fromEnvironment();

    expect([$read->isOn(), $read->children(), $read->checksChanges(), $read->killersFirst()])
        ->toBe([true, 4, false, false]);
});

it('reads a run told nothing as one that does not split, checking changes and killers first', function (): void {
    $read = StreamSettings::fromEnvironment();

    expect([$read->isOn(), $read->children(), $read->checksChanges(), $read->killersFirst()])
        ->toBe([false, 1, true, true]);
});

it('reads a child count that is no number, or under one, as one', function (string $children): void {
    putenv(sprintf('%s=%s', GateVariable::SplitChildren->value, $children));

    expect(StreamSettings::fromEnvironment()->children())->toBe(1);
})->with(['many', '0', '-2']);
