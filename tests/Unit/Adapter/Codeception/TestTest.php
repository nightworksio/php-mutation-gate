<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Codeception\Test;
use NightWorksIO\MutationGate\Core\Native\Candidate;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Tests\Fixtures\Codeception\HeldCest;
use NightWorksIO\MutationGate\Tests\Support\CodeceptionTests;

$groups = static fn(Candidate $test): array => array_map(static fn(Group $group): string => $group->name(), $test->groups());

it('names a unit test by its class and method, declared in its file, in its groups and the holding group of each #[Holds] on it', function () use ($groups): void {
    $class = Test::of(CodeceptionTests::held('testIsHeldByItsCase'));
    $method = Test::of(CodeceptionTests::held('testIsHeldByItsOwnPathToo'));

    expect($class->key())->toBe(CodeceptionTests::id('testIsHeldByItsCase'))
        ->and($class->file())->toBe(CodeceptionTests::file())
        ->and($groups($class))->toBe(['fast', 'holds:src/Money.php'])
        ->and($groups($method))->toBe(['fast', 'holds:src/Money.php', 'holds:src/Held.php']);
});

it('names a row of a unit test\'s data set by its number or its name, as PHPUnit does', function (int|string $row, string $named): void {
    expect(Test::of(CodeceptionTests::row('testIsHeldByItsCase', $row))->key())
        ->toBe(sprintf('%s#%s', CodeceptionTests::id('testIsHeldByItsCase'), $named));
})->with([[0, '0'], ['one', 'one']]);

it('names a Cest\'s test by its class and method, and an example by its index', function () use ($groups): void {
    expect(Test::of(CodeceptionTests::cest())->key())->toBe(sprintf('%s::isHeld', HeldCest::class))
        ->and(Test::of(CodeceptionTests::example(1))->key())->toBe(sprintf('%s::isHeld#1', HeldCest::class))
        ->and($groups(Test::of(CodeceptionTests::cest())))->toBe(['holds:src/Held.php']);
});

it('names a Cept by its file\'s name and its own, in the groups it declares', function () use ($groups): void {
    $cept = Test::of(CodeceptionTests::cept());
    $grouped = CodeceptionTests::cept();
    $grouped->getMetadata()->setGroups(['smoke', 'slow']);

    expect($cept->key())->toBe('SignInCept.php:signs in')
        ->and($cept->file())->toBe('/project/tests/SignInCept.php')
        ->and($groups($cept))->toBe([])
        ->and($groups(Test::of($grouped)))->toBe(['smoke', 'slow']);
});
