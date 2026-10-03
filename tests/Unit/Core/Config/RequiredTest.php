<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Required;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('reads an option an adapter cannot do without, and says so where the options give none', function (): void {
    $options = Configs::options('{"bucket": "ledgers", "prefix": 3}');

    expect(Required::text($options, 'bucket'))->toBe('ledgers')
        ->and(Required::text($options, 'account'))->toEqual(Problem::at('account', 'expected the account, got nothing'))
        ->and(Required::text($options, 'prefix'))->toBeInstanceOf(Problem::class);
});
