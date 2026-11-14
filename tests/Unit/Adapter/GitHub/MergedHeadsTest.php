<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\Api;
use NightWorksIO\MutationGate\Adapter\GitHub\MergedHeads;
use NightWorksIO\MutationGate\Core\Change\ProvingRange;
use NightWorksIO\MutationGate\Core\Change\Revision;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

it('proves nothing since a base, and asks GitHub nothing, without the pull requests\' ledgers to read', function (): void {
    $asked = 0;
    $client = new MockHttpClient(static function () use (&$asked): MockResponse {
        $asked++;

        return new MockResponse('{}');
    });
    $heads = MergedHeads::of(Api::at($client, 'https://api.github.com', ''), 'octo/gate', 'head', 'verdict', ProvingRange::standard());

    expect($heads->provedSince(Revision::ref('base')))->toBeFalse()
        ->and($asked)->toBe(0);
});
