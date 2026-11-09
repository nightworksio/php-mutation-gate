<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamSettings;
use NightWorksIO\MutationGate\Core\Test\TestPart;

afterEach(function (): void {
    foreach ([GateVariable::Split, GateVariable::SplitChildren, GateVariable::SplitChangeCheck, GateVariable::SplitKillersFirst, GateVariable::SplitPart] as $variable) {
        putenv($variable->value);
    }
});

it('tells a run nothing where it does not split its streams', function (): void {
    expect(StreamSettings::off()->variables())->toBe([])
        ->and(StreamSettings::of(enabled: false, children: 3, changeCheck: true, killersFirst: true)->variables())->toBe([]);
});

it('tells a run that splits its streams how, at least one child at once', function (): void {
    expect(StreamSettings::of(enabled: true, children: 3, changeCheck: false, killersFirst: true)->variables())->toBe([
        'MUTATION_GATE_SPLIT' => '1',
        'MUTATION_GATE_SPLIT_CHILDREN' => '3',
        'MUTATION_GATE_SPLIT_CHANGE_CHECK' => '0',
        'MUTATION_GATE_SPLIT_KILLERS_FIRST' => '1',
    ])->and(StreamSettings::of(enabled: true, children: 0, changeCheck: true, killersFirst: false)->variables())->toBe([
        'MUTATION_GATE_SPLIT' => '1',
        'MUTATION_GATE_SPLIT_CHILDREN' => '1',
        'MUTATION_GATE_SPLIT_CHANGE_CHECK' => '1',
        'MUTATION_GATE_SPLIT_KILLERS_FIRST' => '0',
    ]);
});

it('reads back what it told a run', function (): void {
    foreach (StreamSettings::of(enabled: true, children: 4, changeCheck: false, killersFirst: false)->variables() as $name => $value) {
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

it('tells a run its part of the test files, and nothing of every test file', function (): void {
    expect(StreamSettings::parting(TestPart::of(2, 3)))->toBe(['MUTATION_GATE_SPLIT_PART' => '2/3'])
        ->and(StreamSettings::parting(TestPart::whole()))->toBe([]);
});

it('reads back the part it told a run, and every test file where it told none there is', function (string $told, TestPart $part): void {
    putenv(sprintf('%s=%s', GateVariable::SplitPart->value, $told));

    expect(StreamSettings::fromEnvironment()->part())->toEqual($part);
})->with([
    'a part' => ['2/3', fn(): TestPart => TestPart::of(2, 3)],
    'the last part' => ['3/3', fn(): TestPart => TestPart::of(3, 3)],
    'past the last part' => ['4/3', fn(): TestPart => TestPart::whole()],
    'part nought' => ['0/3', fn(): TestPart => TestPart::whole()],
    'no count' => ['2', fn(): TestPart => TestPart::whole()],
    'no number' => ['two/three', fn(): TestPart => TestPart::whole()],
]);

it('reads a run told no part as judged by every test file', function (): void {
    expect(StreamSettings::fromEnvironment()->part())->toEqual(TestPart::whole())
        ->and(StreamSettings::off()->part())->toEqual(TestPart::whole());
});
