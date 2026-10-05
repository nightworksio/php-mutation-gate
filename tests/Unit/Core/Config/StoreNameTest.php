<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\StoreName;
use NightWorksIO\MutationGate\Core\Config\StoreOption;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('reads each name its service allows', function (StoreName $name, string $text): void {
    expect($name->text()->admits($text))->toBeTrue();
})->with([
    'a bucket' => [StoreName::GcsBucket, 'acme-ledgers'],
    'a bucket with dots and underscores' => [StoreName::GcsBucket, 'ledgers.acme_example.com'],
    'an account' => [StoreName::AzureAccount, 'acmeledgers2'],
    'a container' => [StoreName::AzureContainer, 'mutation-ledgers'],
    'an AWS region' => [StoreName::S3Region, 'us-gov-west-1'],
    'R2\'s region' => [StoreName::S3Region, 'auto'],
]);

it('refuses a name that would carry a request elsewhere, or that its service does not allow', function (StoreName $name, string $text): void {
    expect($name->text()->admits($text))->toBeFalse();
})->with(function (): iterable {
    $hostile = ['evil.com/', 'x?sig=', 'a#', '..', 'acme@evil.example', 'acme:443', 'acme%2fx', "acme\n", 'ac me'];

    foreach (StoreName::cases() as $name) {
        foreach ($hostile as $text) {
            yield sprintf('%s %s', $name->name, json_encode($text)) => [$name, $text];
        }
    }

    yield 'an uppercase bucket' => [StoreName::GcsBucket, 'Acme-Ledgers'];
    yield 'an uppercase account' => [StoreName::AzureAccount, 'AcmeLedgers'];
    yield 'an uppercase container' => [StoreName::AzureContainer, 'Ledgers'];
    yield 'an uppercase region' => [StoreName::S3Region, 'US-EAST-1'];
    yield 'a dot in an account' => [StoreName::AzureAccount, 'acme.evil'];
    yield 'a slash in a region' => [StoreName::S3Region, 'eu-west-1/x'];
    yield 'a dot in a region' => [StoreName::S3Region, 'eu-west-1.evil'];
    yield 'a doubled hyphen in a container' => [StoreName::AzureContainer, 'mutation--ledgers'];
    yield 'a short account' => [StoreName::AzureAccount, 'ab'];
});

it('names each name as a refusal reads it, and writes its pattern into the schema', function (StoreName $name, string $what): void {
    expect($name->text()->expected())->toBe($what)
        ->and($name->text()->schema()->line())
        ->toBe(sprintf('{"type":"string","minLength":1,"pattern":%s}', json_encode($name->value, JSON_UNESCAPED_SLASHES)));
})->with([
    [StoreName::GcsBucket, 'a Cloud Storage bucket name'],
    [StoreName::AzureAccount, 'a storage account name'],
    [StoreName::AzureContainer, 'a container name'],
    [StoreName::S3Region, 'a region'],
]);

it('answers the name an option holds, none where it gives none, and the problem of anything else', function (): void {
    $options = Configs::options('{"account": "acme", "container": "../x", "region": 1}');

    expect(StoreName::AzureAccount->in($options, StoreOption::Account))->toBe('acme')
        ->and(StoreName::AzureContainer->in($options, StoreOption::PublicContainer))->toEqual(NotGiven::value())
        ->and(StoreName::AzureContainer->in($options, StoreOption::Container))
        ->toEqual(Problem::at('container', 'expected a container name, got "../x"'))
        ->and(StoreName::S3Region->in($options, StoreOption::Region))
        ->toEqual(Problem::at('region', 'expected text, got 1'));
});
