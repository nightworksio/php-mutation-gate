<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Order\NotAnOption;
use NightWorksIO\MutationGate\Adapter\Pest\Order\Option;

it('reads an argument as the option it is, alone or with its value after =', function (): void {
    expect(Option::in('--cache-directory'))->toBe(Option::CacheDirectory)
        ->and(Option::in('--cache-directory=/tmp/c'))->toBe(Option::CacheDirectory)
        ->and(Option::in('--order-by=random'))->toBe(Option::OrderBy)
        ->and(Option::in('--do-not-cache-result'))->toBe(Option::DoNotCacheResult)
        ->and(Option::in('--cache-directoryx'))->toBe(NotAnOption::Argument)
        ->and(Option::in('--bail'))->toBe(NotAnOption::Argument);
});

it('knows which options take a value that can follow as the next argument', function (): void {
    expect(array_map(static fn(Option $option): bool => $option->takesAValue(), Option::cases()))
        ->toBe([true, true, false, false, false, false]);
});
