<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\TestId;

it('holds its pattern', function (): void {
    expect(Filter::matching('KernelTest|BootTest')->pattern())->toBe('KernelTest|BootTest');
});

it('selects no test at all with the filter of nothing', function (): void {
    $pattern = Filter::nothing()->pattern();

    expect($pattern)->toBe('(?!)')
        ->and(preg_match(sprintf('/%s/', $pattern), 'Tests\\MoneyTest::adds'))->toBe(0)
        ->and(preg_match(sprintf('/%s/', $pattern), ''))->toBe(0);
});

it('compiles as PHPUnit reads it, as written or wrapped, and not past what PCRE compiles', function (): void {
    expect(Filter::matching('MoneyTest::adds')->compiles())->toBeTrue()
        ->and(Filter::matching('/a}b/')->compiles())->toBeTrue()
        ->and(Filter::matching('(')->compiles())->toBeFalse()
        ->and(Filter::matching(str_repeat('MoneyTest::adds|', 5000))->compiles())->toBeFalse()
        ->and(Filter::nothing()->compiles())->toBeTrue();
});

it('raises nothing of its own while it finds a pattern PCRE cannot compile', function (): void {
    error_clear_last();

    expect(Filter::matching('(')->compiles())->toBeFalse()
        ->and(error_get_last())->toBeNull();
});

it('selects a name as PHPUnit reads the pattern: as written where it compiles, wrapped and caseless where it does not', function (string $pattern, string $name, bool $selected): void {
    expect(Filter::matching($pattern)->selects(TestId::of($name)))->toBe($selected);
})->with([
    'wrapped, matching' => ['MoneySpec::it_adds', 'spec\MoneySpec::it_adds_two', true],
    'wrapped, caseless' => ['moneyspec', 'spec\MoneySpec::it_adds', true],
    'wrapped, not matching' => ['it_rounds$', 'spec\MoneySpec::it_adds', false],
    'as written, matching' => ['/::it_adds$/', 'spec\MoneySpec::it_adds', true],
    'as written, case and all' => ['/moneyspec/', 'spec\MoneySpec::it_adds', false],
    'nothing' => ['(?!)', 'spec\MoneySpec::it_adds', false],
    'compiling neither way' => ['(', 'spec\MoneySpec::it_adds(', false],
]);
