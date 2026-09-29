<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;

it('carries the sentence the user reads', function (): void {
    expect(CannotJudge::because('The plan has no shard 3.')->why())->toBe('The plan has no shard 3.');
});
