<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\PHPStan\Rules\NoManyMethodsRule;
use NightWorksIO\MutationGate\PHPStan\Rules\NoMixedOutsideDecodersRule;
use NightWorksIO\MutationGate\PHPStan\Rules\NoSharedLiteralArgumentRule;
use NightWorksIO\MutationGate\PHPStan\Rules\NoStringClosedSetRule;
use NightWorksIO\MutationGate\PHPStan\Rules\OneHomePerValueRule;
use NightWorksIO\MutationGate\PHPStan\Rules\ReadonlyPublicPropertyRule;
use NightWorksIO\MutationGate\Tests\Support\PhpstanNeon;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// D8, D9, D10, D11, D12 and H3: every file, constant and method phpstan.neon
// exempts is still there. A fixed site that leaves its entry behind would exempt whatever
// comes to take its name.

/** Whether a class still declares a constant or an enum case, as `Class::NAME`, of any visibility. */
function isDeclared(string $constant): bool
{
    [$class, $name] = explode('::', $constant, 2);

    return class_exists($class) && new ReflectionClass($class)->hasConstant($name);
}

/** Whether a class still declares a method, as `Class::method`. */
function isAMethod(string $method): bool
{
    [$class, $name] = explode('::', $method, 2);

    return class_exists($class) && method_exists($class, $name);
}

it('reads the exemptions it judges', function (): void {
    expect(PhpstanNeon::files(NoStringClosedSetRule::class, 'vocabularyIn'))->not->toBe([])
        ->and(PhpstanNeon::members(OneHomePerValueRule::class, 'coincidences'))->not->toBe([])
        ->and(PhpstanNeon::members(NoSharedLiteralArgumentRule::class, 'allowIn'))->not->toBe([]);
});

it('exempts only files that are there from the closed-set rule', function (): void {
    $gone = array_values(array_filter(
        [
            ...PhpstanNeon::files(NoStringClosedSetRule::class, 'vocabularyIn'),
            ...PhpstanNeon::files(NoStringClosedSetRule::class, 'awaiting'),
        ],
        static fn(string $path): bool => ! is_file(Tree::at($path)),
    ));

    // D8
    expect($gone)->toBe([], sprintf(
        "phpstan.neon exempts files from D8 that are not there:\n  %s\n\nTake each one off the list (D8).",
        implode("\n  ", $gone),
    ));
});

it('exempts only constants that are still declared from the one-home rule', function (): void {
    $gone = array_values(array_filter(
        [
            ...PhpstanNeon::members(OneHomePerValueRule::class, 'coincidences'),
            ...PhpstanNeon::members(OneHomePerValueRule::class, 'awaiting'),
        ],
        static fn(string $constant): bool => ! isDeclared($constant),
    ));

    // D9
    expect($gone)->toBe([], sprintf(
        "phpstan.neon exempts constants from D9 that no class declares:\n  %s\n\nTake each one off the list (D9).",
        implode("\n  ", $gone),
    ));
});

it('exempts only methods that are still declared from the shared-literal rule', function (): void {
    $gone = array_values(array_filter(
        PhpstanNeon::members(NoSharedLiteralArgumentRule::class, 'allowIn'),
        static fn(string $method): bool => ! isAMethod($method),
    ));

    // D12
    expect($gone)->toBe([], sprintf(
        "phpstan.neon exempts methods from D12 that no class declares:\n  %s\n\nTake each one off the list (D12).",
        implode("\n  ", $gone),
    ))
        ->and(isAMethod('NightWorksIO\MutationGate\Core\CannotJudge::because'))->toBeTrue()
        ->and(isAMethod('NightWorksIO\MutationGate\Core\CannotJudge::nowhere'))->toBeFalse();
});

it('exempts only files that are there from the method cap, the readonly rule and the mixed rule\'s protocols', function (): void {
    $exempt = [
        PhpstanNeon::files(NoManyMethodsRule::class, 'allowIn'),
        PhpstanNeon::files(ReadonlyPublicPropertyRule::class, 'allowIn'),
        PhpstanNeon::files(NoMixedOutsideDecodersRule::class, 'protocols'),
    ];
    $gone = array_values(array_filter(array_merge(...$exempt), static fn(string $path): bool => ! is_file(Tree::at($path))));

    // H3, D11, D10
    expect($exempt)->each->not->toBe([])
        ->and($gone)->toBe([], sprintf(
            "phpstan.neon exempts files from H3, D11 or D10 that are not there:\n  %s\n\nTake each one off the list (H3, D11, D10).",
            implode("\n  ", $gone),
        ));
});
