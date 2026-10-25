<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\CiRequest;
use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;

it('lists each CI init writes a definition for, in the order the plans are built in, without plain JSON', function (): void {
    expect(CiRequest::written())->toBe([
        BuiltinCiPlan::GitHub,
        BuiltinCiPlan::GitLab,
        BuiltinCiPlan::Buildkite,
        BuiltinCiPlan::CircleCi,
        BuiltinCiPlan::Azure,
        BuiltinCiPlan::Bitbucket,
        BuiltinCiPlan::Jenkins,
    ]);
});
