<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Hold\Standing;

it('names each place a #[Holds] can stand', function (): void {
    expect(array_map(static fn(Standing $standing): string => $standing->value, Standing::cases()))->toBe([
        'test closure',
        'describe closure',
        'hook closure',
        'dataset closure',
        'kept closure',
        'other closure',
        'named function',
        'test class',
        'test method',
        'elsewhere',
    ]);
});

it('knows where Pest passes a #[Holds] to its filter, and where Pest loads PHPUnit\'s own', function (Standing $standing, bool $filtered, bool $phpUnits): void {
    expect([$standing->isFiltered(), $standing->isPhpUnits()])->toBe([$filtered, $phpUnits]);
})->with([
    'a test closure' => [Standing::TestClosure, true, false],
    'a describe closure' => [Standing::DescribeClosure, true, false],
    'a hook closure' => [Standing::HookClosure, false, false],
    'a dataset closure' => [Standing::DatasetClosure, false, false],
    'a named function' => [Standing::NamedFunction, false, false],
    'a test class' => [Standing::TestClass, true, true],
    'a test method' => [Standing::TestMethod, true, true],
]);

it('calls each place the way a refusal names it', function (Standing $standing, string $called): void {
    expect($standing->called())->toBe($called);
})->with([
    [Standing::HookClosure, 'a beforeEach, afterEach, beforeAll or afterAll closure'],
    [Standing::DatasetClosure, 'a dataset closure'],
    [Standing::NamedFunction, 'a named function'],
    [Standing::TestClass, 'class'],
    [Standing::TestMethod, 'method'],
    [Standing::KeptClosure, 'kept closure'],
]);
