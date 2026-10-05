<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Required;
use NightWorksIO\MutationGate\Core\Config\StoreName;
use NightWorksIO\MutationGate\Core\Config\StoreOption;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('reads an option an adapter cannot do without, and says so where the options give none', function (): void {
    $options = Configs::options('{"bucket": "ledgers", "prefix": 3}');

    expect(Required::text($options, 'bucket'))->toBe('ledgers')
        ->and(Required::text($options, 'account'))->toEqual(Problem::at('account', 'expected the account, got nothing'))
        ->and(Required::text($options, 'prefix'))->toBeInstanceOf(Problem::class);
});

it('reads a name an adapter cannot do without, as its service allows it', function (): void {
    $options = Configs::options('{"account": "acme", "container": "../x", "region": 1}');

    expect(Required::named($options, StoreOption::Account, StoreName::AzureAccount))->toBe('acme')
        ->and(Required::named($options, StoreOption::Bucket, StoreName::GcsBucket))
        ->toEqual(Problem::at('bucket', 'expected the bucket, got nothing'))
        ->and(Required::named($options, StoreOption::Container, StoreName::AzureContainer))
        ->toEqual(Problem::at('container', 'expected a container name, got "../x"'))
        ->and(Required::named($options, StoreOption::Region, StoreName::S3Region))
        ->toEqual(Problem::at('region', 'expected text, got 1'));
});
