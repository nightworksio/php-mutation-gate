<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Native\Candidate;
use NightWorksIO\MutationGate\Core\Native\ListedTests;
use NightWorksIO\MutationGate\Core\Runner\Program;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;

it('writes every test in its order, and each group\'s tests by the group\'s name', function (): void {
    expect(ListedTests::encoded(
        Candidate::of('T::a', '/t.php', Group::holding('src/A.php')),
        Candidate::of('T::b', '/t.php', Group::holding('src/A.php'), Group::named('slow')),
        Candidate::of('T::c', '/t.php'),
    ))->toBe('{"listed":["T::a","T::b","T::c"],"groups":{"holds:src/A.php":["T::a","T::b"],"slow":["T::b"]}}')
        ->and(ListedTests::encoded())->toBe('{"listed":[],"groups":[]}');
});

it('reads back the tests and their groups a run listed', function (): void {
    $json = ListedTests::encoded(Candidate::of('T::a', '/t.php', Group::holding('src/A.php')), Candidate::of('T::b', '/t.php'));
    $listing = ListedTests::read(Ran::finished(succeeded: true, output: ''), $json, '/l.json', Program::PhpSpec);

    expect($listing instanceof TestListing ? $listing->tests() : $listing)->toEqual(TestIds::of(TestId::of('T::a'), TestId::of('T::b')))
        ->and($listing instanceof TestListing ? $listing->inGroup(Group::holding('src/A.php')) : $listing)->toEqual(TestIds::of(TestId::of('T::a')))
        ->and(ListedTests::read(Ran::finished(succeeded: true, output: ''), ListedTests::encoded(), '/l.json', Program::PhpSpec))
        ->toEqual(TestListing::of(TestIds::none()));
});

it('cannot judge where the run failed, wrote no list, or wrote one with no tests, naming the program and saying what it printed', function (bool $succeeded, string|false $written): void {
    $listing = ListedTests::read(Ran::finished(succeeded: $succeeded, output: 'It said this'), $written, '/l.json', Program::PhpSpec);

    expect($listing)->toEqual(CannotJudge::because("PhpSpec did not list the tests of the suite: /l.json holds no list of them. It said:\nIt said this"));
})->with([
    'failed' => [false, '{"listed":[]}'],
    'wrote nothing' => [true, false],
    'wrote no JSON' => [true, 'not json'],
    'wrote no tests' => [true, '{"groups":{}}'],
]);
