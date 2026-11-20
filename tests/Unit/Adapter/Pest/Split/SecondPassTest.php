<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\SecondPass;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamSettings;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamVerdicts;
use NightWorksIO\MutationGate\Tests\Support\Mutations;
use Pest\Mutate\Support\MutationTestResult;

it('streams again every covering test of a mutant the first pass forked at its likely killer alone, and of one it never forked those its part does not hold', function (): void {
    $covering = ['T::killer', 'T::other', 'T::elsewhere'];
    $pass = new SecondPass(
        ['forked' => ['money', 3, 'T::killer', []], 'unforked' => ['money', 4, '', []]],
        ['forked' => $covering, 'unforked' => $covering],
        ['forked' => $covering, 'unforked' => ['T::killer', 'T::other']],
        [],
    );
    $byId = [
        'forked' => Mutations::test('src/Money.php', 'forked', MutationTestResult::None),
        'unforked' => Mutations::test('src/Money.php', 'unforked', MutationTestResult::None),
    ];

    [$again, $left] = $pass->of($byId, [], StreamVerdicts::none(), StreamSettings::of(enabled: true, children: 1, changeCheck: true, killersFirst: true));

    expect($again)->toBe(['forked' => ['money', 3, '', []], 'unforked' => ['money', 4, '', []]])
        ->and($left)->toBe(['forked' => $covering, 'unforked' => ['T::elsewhere']]);
});
