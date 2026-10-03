<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Azure\ContainerOptions;
use NightWorksIO\MutationGate\Adapter\Azure\PublicAccess;
use NightWorksIO\MutationGate\Adapter\Http\HttpExchange;
use NightWorksIO\MutationGate\Core\Doctor\AnonymousReadsRefused;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Cloud;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** The `azure` store's options, with this public URL where one is given. */
function publicAccessOptions(string $publicUrl = ''): ContainerOptions
{
    $options = ContainerOptions::read(Configs::options((string) json_encode([
        'account' => 'acme',
        'container' => 'ledgers',
        'prefix' => 'gate',
        ...$publicUrl === '' ? [] : ['publicUrl' => $publicUrl],
    ])));

    return $options instanceof ContainerOptions ? $options : throw new LogicException('The options are invalid.');
}

it('finds an account that refuses anonymous reads by the 409 it answers an unversioned anonymous request', function (): void {
    $cloud = new Cloud()->answering('https://acme.blob.core.windows.net/public?restype=container', 409, 'PublicAccessNotPermitted');

    expect(PublicAccess::refused($cloud->exchange(), publicAccessOptions('https://acme.blob.core.windows.net/public/')))
        ->toEqual(AnonymousReadsRefused::by('acme', 'https://acme.blob.core.windows.net/public/'))
        ->and($cloud->requests[0]['headers'])->not->toHaveKeys(['authorization', 'x-ms-version']);
});

it('finds nothing where the account answers otherwise, none answers, or no public URL is named', function (): void {
    $open = new Cloud()->answering('https://acme.blob.core.windows.net/public?restype=container', 404, 'ResourceNotFound');
    $unreached = HttpExchange::over(new MockHttpClient(new MockResponse('', ['error' => 'Could not resolve host'])));
    $none = new Cloud();

    expect(PublicAccess::refused($open->exchange(), publicAccessOptions('https://acme.blob.core.windows.net/public')))->toEqual(NotGiven::value())
        ->and(PublicAccess::refused($unreached, publicAccessOptions('https://acme.blob.core.windows.net/public')))->toEqual(NotGiven::value())
        ->and(PublicAccess::refused($none->exchange(), publicAccessOptions()))->toEqual(NotGiven::value())
        ->and($none->requests)->toBe([]);
});
