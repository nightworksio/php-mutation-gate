<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Native\Candidate;
use NightWorksIO\MutationGate\Core\Native\Selected;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

$held = static fn(): Candidate => Candidate::of('Tests\HeldTest::holds_its_path', '/p/tests/HeldTest.php', Group::holding('src/Held.php'));

it('selects every test where it is told nothing that narrows the run', function () use ($held): void {
    expect(Selected::narrowedTo(NotGiven::value(), NotGiven::value(), NotGiven::value(), NotGiven::value())->selects($held()))->toBeTrue();
});

it('selects only a test that every one of its narrowings selects', function (string $listed, string $group, string $pattern, string $file, bool $selected) use ($held): void {
    $narrowed = Selected::narrowedTo(
        $listed === '' ? NotGiven::value() : TestIds::of(TestId::of($listed)),
        $group === '' ? NotGiven::value() : Group::holding($group),
        $pattern === '' ? NotGiven::value() : Filter::matching($pattern),
        $file === '' ? NotGiven::value() : [$file],
    );

    expect($narrowed->selects($held()))->toBe($selected);
})->with([
    'told by id' => ['Tests\HeldTest::holds_its_path', '', '', '', true],
    'not told' => ['Tests\HeldTest::other', '', '', '', false],
    'in the group' => ['', 'src/Held.php', '', '', true],
    'outside the group' => ['', 'src/Other.php', '', '', false],
    'matching the filter' => ['', '', 'its_path', '', true],
    'not matching the filter' => ['', '', 'nothing', '', false],
    'declared in a file told' => ['', '', '', '/p/tests/HeldTest.php', true],
    'declared in no file told' => ['', '', '', '/p/tests/OtherTest.php', false],
    'told by id, outside the group' => ['Tests\HeldTest::holds_its_path', 'src/Other.php', '', '', false],
]);

it('selects no test for a listing', function () use ($held): void {
    expect(Selected::listing()->selects($held()))->toBeFalse();
});
