<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Cost\RunAccount;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Report\Trend;

/**
 * The runs `trend.json` held before this one, which a verdict on the default
 * branch reads from where the badge is published (ADR-0017): what the last 30
 * days saved, and the alerts, say them. Off the default branch there is no
 * trend, and on it a file that is not there holds no run yet.
 *
 * @internal
 */
final readonly class TrendBefore
{
    public static function on(RunAccount $account, RunOn $runOn, Directory $project, Path $published): RunAccount
    {
        $scope = $runOn->scope();
        $default = $runOn->defaultBranch();

        if (! $scope instanceof Scope || ! $default instanceof Scope || ! $scope->equals($default)) {
            return $account;
        }

        $file = $project->read($published->child(Path::of(Trend::FILE)));

        return $account->after($file instanceof Contents ? Trend::decode($file->text()) : Trend::none());
    }
}
