<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\ChildLimit;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamPlan;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamPlanFile;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

it('reads a stream\'s plan as Pest\'s own process wrote it, each part typed', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'plan.json', (string) json_encode([
        'results' => '/tmp/results.jsonl',
        'sites' => ['f1' => [3 => ['m1', 'T::kills', ['GATE_A' => 'x']], 4 => ['m2', '']]],
        'paths' => ['f1' => 'src/Money.php'],
        'times' => ['T::kills' => 0.25, 'T::slow' => 2],
        'children' => 3,
        'multiple' => 3,
        'floor' => 1.5,
        'longest' => 30,
    ]));

    expect(StreamPlanFile::read(sprintf('%s/plan.json', $root)))->toEqual(new StreamPlan(
        '/tmp/results.jsonl',
        ['f1' => [3 => ['m1', 'T::kills', ['GATE_A' => 'x']], 4 => ['m2', '', []]]],
        ['f1' => 'src/Money.php'],
        ['T::kills' => 0.25, 'T::slow' => 2.0],
        3,
        new ChildLimit(3.0, 1.5, 30.0),
    ));
});

it('leaves out what is not as written, and holds at least one child', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'plan.json', (string) json_encode([
        'results' => 'r',
        'sites' => ['f1' => [1 => ['m1', 7], 2 => 'site', 3 => [5, 'T'], 4 => ['m4', 'T', ['A' => 1, 'B' => 'b']]], 'f2' => 'none'],
        'paths' => ['f1' => 7, 'f2' => 'src/Shapes.php'],
        'times' => ['T::a' => 'slow', 'T::b' => 1.0],
        'children' => 0,
        'multiple' => 'three',
    ]));
    Scratch::write($root, 'unlisted.json', (string) json_encode(['results' => 'r', 'sites' => 'none', 'children' => '2']));

    expect(StreamPlanFile::read(sprintf('%s/plan.json', $root)))->toEqual(new StreamPlan(
        'r',
        ['f1' => [4 => ['m4', 'T', ['B' => 'b']]], 'f2' => []],
        ['f2' => 'src/Shapes.php'],
        ['T::b' => 1.0],
        1,
        new ChildLimit(0.0, 0.0, 0.0),
    ))
        ->and(StreamPlanFile::read(sprintf('%s/unlisted.json', $root)))
        ->toEqual(new StreamPlan('r', [], [], [], 1, new ChildLimit(0.0, 0.0, 0.0)));
});

it('reads no plan from a file that is not there, is not JSON, or names no results file', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'broken.json', '{');
    Scratch::write($root, 'nameless.json', (string) json_encode(['sites' => []]));
    Scratch::write($root, 'numbered.json', (string) json_encode(['results' => 7]));

    expect(array_map(
        static fn(string $file): StreamPlan|false => StreamPlanFile::read(sprintf('%s/%s', $root, $file)),
        ['absent.json', 'broken.json', 'nameless.json', 'numbered.json'],
    ))->toBe([false, false, false, false]);
});

it('names the mutants of each schema whose source file the process loaded before the schema could be served', function (): void {
    $plan = new StreamPlan(
        'r',
        ['f1' => [1 => ['m1', '', []], 2 => ['m2', '', []]], 'f2' => [1 => ['m3', '', []]]],
        ['f1' => 'src/Money.php', 'f2' => 'src/Shapes.php', 'f3' => 'src/Held.php'],
        [],
        1,
        new ChildLimit(0.0, 0.0, 0.0),
    );

    expect($plan->unservedIn(['src/Money.php', 'src/Held.php']))->toBe(['m1', 'm2'])
        ->and($plan->unservedIn(['src/Shapes.php']))->toBe(['m3'])
        ->and($plan->unservedIn([]))->toBe([]);
});
