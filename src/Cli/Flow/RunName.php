<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Time\Instant;

use function sprintf;

/**
 * The run that establishes a proof, as the ledger names it: on GitHub
 * `github:<run id>/<attempt>`, on GitLab `gitlab:<pipeline id>`, on Buildkite
 * `buildkite:<build id>`, on CircleCI `circleci:<workflow id>`, and otherwise
 * `local:<time of the run>`. Every proof it establishes records the base its
 * keys were built on.
 */
final readonly class RunName
{
    public static function of(Variables $environment, Instant $at, Digest $base): Run
    {
        $name = match (true) {
            $environment->has('GITHUB_RUN_ID') => sprintf(
                'github:%s/%s',
                $environment->valueOf('GITHUB_RUN_ID'),
                $environment->valueOf('GITHUB_RUN_ATTEMPT'),
            ),
            $environment->has('CI_PIPELINE_ID') => sprintf('gitlab:%s', $environment->valueOf('CI_PIPELINE_ID')),
            $environment->has('BUILDKITE_BUILD_ID') => sprintf(
                'buildkite:%s',
                $environment->valueOf('BUILDKITE_BUILD_ID'),
            ),
            $environment->has('CIRCLE_WORKFLOW_ID') => sprintf(
                'circleci:%s',
                $environment->valueOf('CIRCLE_WORKFLOW_ID'),
            ),
            default => sprintf('local:%s', $at->value()),
        };

        return Run::of($name, $at, $base);
    }
}
