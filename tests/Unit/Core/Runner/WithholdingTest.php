<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Withholding;

it('names every inherited variable, each withheld one as false', function (): void {
    expect(Withholding::of(Withheld::of('DEPLOY_*', 'GITHUB_TOKEN'), [
        'PATH' => '/usr/bin',
        'DEPLOY_KEY' => 'secret',
        'GITHUB_TOKEN' => 'ghs_1',
        'GITHUB_TOKENS' => 'kept',
    ]))->toBe(['PATH' => '/usr/bin', 'DEPLOY_KEY' => false, 'GITHUB_TOKEN' => false, 'GITHUB_TOKENS' => 'kept']);
});
