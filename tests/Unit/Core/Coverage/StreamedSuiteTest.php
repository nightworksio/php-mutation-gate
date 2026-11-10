<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\StreamedSuite;
use NightWorksIO\MutationGate\Core\NotGiven;

$passed = static fn(float $seconds): array => ['passed' => true, 'troubled' => false, 'own' => false, 'seconds' => $seconds];

it('merges two shards: complete only where both are, a test failing or troubled in either, reported lines united', function () use ($passed): void {
    $one = StreamedSuite::of(true, ['A::a' => $passed(1.0)], ['src/A.php' => [3, 5]]);
    $other = StreamedSuite::of(false, ['A::a' => ['passed' => false, 'troubled' => true, 'own' => false, 'seconds' => 0.5]], ['src/A.php' => [4, 5]]);
    $both = $one->and($other)->ofSuites(['Unit']);

    expect($both->reported())->toBe(['src/A.php' => [3, 4, 5]])
        ->and($both->secondsByTest())->toBe(['A::a' => 1.5])
        ->and($both->standsIn())->toBeFalse()
        ->and($one->and($one)->ofSuites(['Unit'])->standsIn())->toBeTrue();
});

it('stands in only where it is complete, ran a test and names its suites', function (StreamedSuite $suite, bool $standsIn): void {
    expect($suite->standsIn())->toBe($standsIn);
})->with([
    'every test passed' => [StreamedSuite::of(true, ['A::a' => ['passed' => true, 'troubled' => false, 'own' => false, 'seconds' => 1.0]], [])->ofSuites(['Unit']), true],
    'no suite named' => [StreamedSuite::of(true, ['A::a' => ['passed' => true, 'troubled' => false, 'own' => false, 'seconds' => 1.0]], []), false],
    'no test ran' => [StreamedSuite::none()->ofSuites(['Unit']), false],
    'ran on its own' => [StreamedSuite::of(true, ['A::a' => ['passed' => true, 'troubled' => false, 'own' => true, 'seconds' => 1.0]], [])->ofSuites(['Unit']), false],
]);

it('reads back what it wrote, and nothing from text that is not a suite', function () use ($passed): void {
    $suite = StreamedSuite::of(true, ['A::a' => $passed(2.0)], ['src/A.php' => [1]])->ofSuites(['Unit', 'Plugins']);

    expect(StreamedSuite::decoded($suite->encoded()))->toEqual($suite)
        ->and(StreamedSuite::decoded('{"complete": true, "suites": [1], "tests": {}, "reported": {}}'))->toBeInstanceOf(NotGiven::class)
        ->and(StreamedSuite::decoded('{"complete": true, "suites": [], "tests": {"A::a": {"passed": "yes"}}, "reported": {}}'))->toBeInstanceOf(NotGiven::class)
        ->and(StreamedSuite::decoded('{"complete": true, "suites": [], "tests": {}, "reported": {"src/A.php": ["1"]}}'))->toBeInstanceOf(NotGiven::class)
        ->and(StreamedSuite::decoded('not json'))->toBeInstanceOf(NotGiven::class)
        ->and(StreamedSuite::decoded('{"suites": []}'))->toBeInstanceOf(NotGiven::class);
});
