<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_map;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Owner\CodeOwners;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Port\ChangeSource;

use function sprintf;

/**
 * The CODEOWNERS a verdict groups survivors by, as it is at the base, so a
 * pull request cannot reroute its own survivors by editing it (ADR-0022): on
 * a pull request, the default branch as the checkout fetched it; otherwise
 * the commit the plan was made on. The file is the first of GitHub's three
 * places that holds one.
 */
final readonly class CodeOwnersFile
{
    private const string UNREAD = 'CODEOWNERS cannot be read at %s, so no survivor has an owner: %s';

    public static function of(ChangeSource $changes, Plan $plan): CodeOwners
    {
        $base = $plan->runOn()->isPullRequest() ? Standing::planned($plan)->fetchedDefaultBranch() : $plan->commit();
        $files = $changes->filesAt(Paths::of(...array_map(Path::of(...), CodeOwners::LOCATIONS)), $base);

        if ($files instanceof CannotTell) {
            return CodeOwners::unread(sprintf(self::UNREAD, $base->name(), $files->why()));
        }

        foreach (CodeOwners::LOCATIONS as $location) {
            $file = $files->at(Path::of($location), Missing::at(Path::of($location)));

            if ($file instanceof Contents) {
                return CodeOwners::read(Path::of($location), $file->text());
            }
        }

        return CodeOwners::none();
    }
}
