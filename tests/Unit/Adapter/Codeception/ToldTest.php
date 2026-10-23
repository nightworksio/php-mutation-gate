<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Codeception\Option;
use NightWorksIO\MutationGate\Adapter\Codeception\Test;
use NightWorksIO\MutationGate\Adapter\Codeception\Told;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\CodeceptionTests;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * Whether what the extension was told selects a test of HeldTest, by its method.
 *
 * @param non-empty-string $method
 */
function selectsHeld(Told $told, string $method): bool
{
    return $told->selected()->selects(Test::of(CodeceptionTests::held($method)));
}


it('names the files to write a coverage map and a listing to, and the vendor directory, and none where told none', function (): void {
    $told = Told::from([Option::Coverage->value => '/m.php', Option::Listing->value => '/l.json', Option::Vendor->value => '/p/vendor']);
    $untold = Told::from([]);

    expect([$told->coverage(), $told->listing(), $told->vendor()])->toBe(['/m.php', '/l.json', '/p/vendor'])
        ->and($untold->coverage())->toBeInstanceOf(NotGiven::class)
        ->and($untold->listing())->toBeInstanceOf(NotGiven::class)
        ->and($untold->vendor())->toBeInstanceOf(NotGiven::class)
        ->and(Told::from([Option::Coverage->value => ''])->coverage())->toBeInstanceOf(NotGiven::class);
});

it('selects every test where told nothing that narrows the run', function (): void {
    expect(selectsHeld(Told::from([]), 'testIsHeldByItsCase'))->toBeTrue()
        ->and(selectsHeld(Told::from([]), 'testIsHeldByItsOwnPathToo'))->toBeTrue();
});

it('selects the tests its selection file lists, by id, and none where the file is empty or gone', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'selected.txt', sprintf("%s\n\n", CodeceptionTests::id('testIsHeldByItsCase')));
    Scratch::write($root, 'empty.txt', '');
    $listed = Told::from([Option::Selected->value => sprintf('%s/selected.txt', $root)]);
    $empty = Told::from([Option::Selected->value => sprintf('%s/empty.txt', $root)]);
    $gone = Told::from([Option::Selected->value => sprintf('%s/gone.txt', $root)]);

    expect(selectsHeld($listed, 'testIsHeldByItsCase'))->toBeTrue()
        ->and(selectsHeld($listed, 'testIsHeldByItsOwnPathToo'))->toBeFalse()
        ->and(selectsHeld($empty, 'testIsHeldByItsCase'))->toBeFalse()
        ->and(selectsHeld($gone, 'testIsHeldByItsCase'))->toBeFalse();
});

it('selects the tests in the group it was told, and those matching the filter', function (): void {
    $grouped = Told::from([Option::Group->value => 'holds:src/Held.php']);
    $filtered = Told::from([Option::Filter->value => 'Too$']);

    expect(selectsHeld($grouped, 'testIsHeldByItsCase'))->toBeFalse()
        ->and(selectsHeld($grouped, 'testIsHeldByItsOwnPathToo'))->toBeTrue()
        ->and(selectsHeld($filtered, 'testIsHeldByItsCase'))->toBeFalse()
        ->and(selectsHeld($filtered, 'testIsHeldByItsOwnPathToo'))->toBeTrue();
});

it('selects the tests of the files it was told, each by its real path, and none of a file that is not there', function (): void {
    $relative = Told::from([Option::Files->value => [sprintf('%s/../Codeception/HeldTest.php', dirname(CodeceptionTests::file()))]]);
    $elsewhere = Told::from([Option::Files->value => ['nowhere/HeldTest.php']]);

    expect(selectsHeld($relative, 'testIsHeldByItsCase'))->toBeTrue()
        ->and(selectsHeld($elsewhere, 'testIsHeldByItsCase'))->toBeFalse();
});

it('selects no test for a listing, which runs none', function (): void {
    expect(selectsHeld(Told::from([Option::Listing->value => '/l.json']), 'testIsHeldByItsCase'))->toBeFalse();
});
