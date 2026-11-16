<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\StreamedSuite;
use NightWorksIO\MutationGate\Core\Coverage\TestEnded;
use NightWorksIO\MutationGate\Core\NotGiven;

$ended = static fn(float $seconds, bool $passed = true, bool $troubled = false, bool $own = false): TestEnded => new TestEnded(
    passed: $passed,
    troubled: $troubled,
    own: $own,
    seconds: $seconds,
);

it('merges two shards: complete only where both are, a test failing or troubled in either, reported lines united', function () use ($ended): void {
    $one = StreamedSuite::of(complete: true, tests: ['A::a' => $ended(1.0)], reported: ['src/A.php' => [3, 5]]);
    $other = StreamedSuite::of(complete: false, tests: ['A::a' => $ended(0.5, passed: false, troubled: true)], reported: ['src/A.php' => [4, 5]]);
    $both = $one->and($other)->ofSuites(['Unit']);

    expect($both->reported())->toBe(['src/A.php' => [3, 4, 5]])
        ->and($both->secondsByTest())->toBe(['A::a' => 1.5])
        ->and($both->standsIn())->toBeFalse()
        ->and($one->and($one)->ofSuites(['Unit'])->standsIn())->toBeTrue();
});

it('stands in only where it is complete, ran a test and names its suites', function (Closure $suite, bool $standsIn): void {
    $built = $suite();

    expect($built instanceof StreamedSuite && $built->standsIn())->toBe($standsIn);
})->with([
    'every test passed' => [static fn(): StreamedSuite => StreamedSuite::of(complete: true, tests: ['A::a' => new TestEnded(passed: true, troubled: false, own: false, seconds: 1.0)], reported: [])->ofSuites(['Unit']), true],
    'no suite named' => [static fn(): StreamedSuite => StreamedSuite::of(complete: true, tests: ['A::a' => new TestEnded(passed: true, troubled: false, own: false, seconds: 1.0)], reported: []), false],
    'no test ran' => [static fn(): StreamedSuite => StreamedSuite::none()->ofSuites(['Unit']), false],
    'ran on its own' => [static fn(): StreamedSuite => StreamedSuite::of(complete: true, tests: ['A::a' => new TestEnded(passed: true, troubled: false, own: true, seconds: 1.0)], reported: [])->ofSuites(['Unit']), false],
]);

it('reads back what it wrote, and nothing from text that is not a suite', function () use ($ended): void {
    $suite = StreamedSuite::of(complete: true, tests: ['A::a' => $ended(2.0)], reported: ['src/A.php' => [1]])->ofSuites(['Unit', 'Plugins']);

    expect(StreamedSuite::decoded($suite->encoded()))->toEqual($suite)
        ->and(StreamedSuite::decoded('{"complete": true, "suites": [1], "tests": {}, "reported": {}}'))->toBeInstanceOf(NotGiven::class)
        ->and(StreamedSuite::decoded('{"complete": true, "suites": [], "tests": {"A::a": {"passed": "yes"}}, "reported": {}}'))->toBeInstanceOf(NotGiven::class)
        ->and(StreamedSuite::decoded('{"complete": true, "suites": [], "tests": {}, "reported": {"src/A.php": ["1"]}}'))->toBeInstanceOf(NotGiven::class)
        ->and(StreamedSuite::decoded('not json'))->toBeInstanceOf(NotGiven::class)
        ->and(StreamedSuite::decoded('{"suites": []}'))->toBeInstanceOf(NotGiven::class);
});

it('counts the tests the streams ran and names the suites they ran', function () use ($ended): void {
    $suite = StreamedSuite::of(complete: true, tests: ['A::a' => $ended(1.0), 'A::b' => $ended(2.0)], reported: [])->ofSuites(['Unit', 'Plugins']);

    expect($suite->count())->toBe(2)
        ->and($suite->suites())->toBe(['Unit', 'Plugins'])
        ->and(StreamedSuite::none()->count())->toBe(0);
});
