<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\GateDefinitions;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\GitHubSettings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;
use function str_contains;

/**
 * GitHub's hosted arm runners for a public repository whose gate runs on
 * x64 (ADR-0035): they are free for a public repository, and the gate's jobs
 * spend fewer minutes there. The gate's workflow keeps `ubuntu-latest` by
 * default; this only suggests the label.
 *
 * @internal
 */
final readonly class ArmRunners
{
    /** The hosted arm runner the gate suggests. */
    public const string LABEL = 'ubuntu-24.04-arm';

    /** What names an arm runner in a workflow, of any Ubuntu release. */
    private const string ARM = '-arm';

    private const string FOUND = '%s is public, and the workflows that run the gate name no arm runner.';

    private const string WHY = <<<'WHY'
        GitHub's hosted arm runners are free for a public repository, and the gate's shards took 29% fewer runner
        minutes on them where the gate measured it. Proofs are keyed by the architecture, so the first run there is
        a full one.
        WHY;

    private const string FIX
        = 'Pass `runs-on: %s` to the reusable workflow, where every extension the tests need builds on arm.';

    public static function in(Observations $observed): Findings
    {
        $gitHub = $observed->asked()->gitHub();
        $definitions = $observed->files()->runningTheGate();

        return $gitHub instanceof GitHubSettings && $gitHub->isPublic() && ! self::namesArm($definitions)
            ? Findings::of(Finding::of(
                Slug::ArmRunners,
                Severity::Advice,
                sprintf(self::FOUND, $gitHub->repository()),
                self::WHY,
                sprintf(self::FIX, self::LABEL),
            ))
            : Findings::none();
    }

    private static function namesArm(GateDefinitions|NotGiven $definitions): bool
    {
        if (! $definitions instanceof GateDefinitions) {
            return false;
        }

        foreach ($definitions as $text) {
            if (str_contains($text, self::ARM)) {
                return true;
            }
        }

        return false;
    }
}
