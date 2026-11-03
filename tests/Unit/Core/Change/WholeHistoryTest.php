<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\WholeHistory;

it('lists every CI\'s setting for a clone of the whole history, in its order, the last after an or', function (): void {
    expect(WholeHistory::settings())->toBe(
        '`fetch-depth: 0` on GitHub\'s `actions/checkout`, `GIT_DEPTH: 0` on GitLab, '
        . '`clone: depth: full` on Bitbucket, or `fetchDepth: 0` on Azure Pipelines\' checkout',
    );
});
