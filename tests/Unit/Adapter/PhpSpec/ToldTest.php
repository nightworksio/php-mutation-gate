<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpSpec\Example;
use NightWorksIO\MutationGate\Adapter\PhpSpec\LaunchOption;
use NightWorksIO\MutationGate\Adapter\PhpSpec\Told;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\PhpSpecExamples;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$spec = static fn(): string => (string) realpath(sprintf('%s/Fixtures/PhpSpec/HeldSpec.php', dirname(__DIR__, 3)));
$selects = static fn(Told $told, string $method): bool => $told->selected()->selects(Example::of(PhpSpecExamples::held($method)));

it('gives PhpSpec its own arguments, in their order, less what the gate told the launcher', function (): void {
    $told = Told::from(['run', LaunchOption::Group->with('g'), '--format=dot', LaunchOption::Coverage->with('/m.php'), '--no-rerun']);

    expect($told->arguments())->toBe(['run', '--format=dot', '--no-rerun']);
});

it('names the files to write a coverage map and a listing to, the last of each where told twice, and none where told neither', function (): void {
    $told = Told::from([LaunchOption::Coverage->with('/a.php'), LaunchOption::Listing->with('/a.json'), LaunchOption::Coverage->with('/b.php'), LaunchOption::Listing->with('/b.json')]);
    $untold = Told::from(['run']);

    expect([$told->coverage(), $told->listing()])->toBe(['/b.php', '/b.json'])
        ->and($untold->coverage())->toBeInstanceOf(NotGiven::class)
        ->and($untold->listing())->toBeInstanceOf(NotGiven::class);
});

it('selects every example where told nothing that narrows the run', function () use ($selects): void {
    $told = Told::from(['run']);

    expect($selects($told, 'it_is_held_by_its_spec'))->toBeTrue()
        ->and($selects($told, 'it_is_held_by_its_own_path_too'))->toBeTrue();
});

it('selects the examples its examples file lists, by id, and none where the file is empty or gone', function () use ($selects): void {
    $root = Scratch::directory();
    Scratch::write($root, 'examples.txt', sprintf("%s\n\n", PhpSpecExamples::id('it_is_held_by_its_spec')));
    Scratch::write($root, 'empty.txt', '');
    $listed = Told::from([LaunchOption::Examples->with(sprintf('%s/examples.txt', $root))]);
    $empty = Told::from([LaunchOption::Examples->with(sprintf('%s/empty.txt', $root))]);
    $gone = Told::from([LaunchOption::Examples->with(sprintf('%s/gone.txt', $root))]);

    expect($selects($listed, 'it_is_held_by_its_spec'))->toBeTrue()
        ->and($selects($listed, 'it_is_held_by_its_own_path_too'))->toBeFalse()
        ->and($selects($empty, 'it_is_held_by_its_spec'))->toBeFalse()
        ->and($selects($gone, 'it_is_held_by_its_spec'))->toBeFalse();
});

it('selects the examples in the last group it was told, and those matching the last filter', function () use ($selects): void {
    $grouped = Told::from([LaunchOption::Group->with('holds:src/Money.php'), LaunchOption::Group->with('holds:src/Held.php')]);
    $filtered = Told::from([LaunchOption::Filter->with('spec$'), LaunchOption::Filter->with('too$')]);

    expect($selects($grouped, 'it_is_held_by_its_spec'))->toBeFalse()
        ->and($selects($grouped, 'it_is_held_by_its_own_path_too'))->toBeTrue()
        ->and($selects($filtered, 'it_is_held_by_its_spec'))->toBeFalse()
        ->and($selects($filtered, 'it_is_held_by_its_own_path_too'))->toBeTrue();
});

it('selects the examples of the spec files it was told, each by its real path, and none of a file that is not there', function () use ($spec, $selects): void {
    $relative = Told::from([LaunchOption::Spec->with(sprintf('%s/../PhpSpec/HeldSpec.php', dirname($spec())))]);
    $elsewhere = Told::from([LaunchOption::Spec->with('nowhere/HeldSpec.php')]);

    expect($selects($relative, 'it_is_held_by_its_spec'))->toBeTrue()
        ->and($selects($elsewhere, 'it_is_held_by_its_spec'))->toBeFalse();
});

it('selects no example for a listing, which runs none', function () use ($selects): void {
    expect($selects(Told::from([LaunchOption::Listing->with('/l.json')]), 'it_is_held_by_its_spec'))->toBeFalse();
});
