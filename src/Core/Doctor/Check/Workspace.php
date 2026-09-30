<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\File\GitIgnore;
use NightWorksIO\MutationGate\Core\File\Workspace as Directory;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/** `.gitignore` leaves the gate's own directory to git. */
final readonly class Workspace
{
    private const string FOUND = '.gitignore does not name %s/.';

    private const string WHY
        = 'The gate keeps each run\'s coverage maps, results and ledger there, and none of them belongs in git.';

    private const string FIX = 'Add the line %s/ to .gitignore, as mutation-gate init does.';

    public static function in(Observations $observed): Findings
    {
        $gitIgnore = $observed->files()->gitIgnore();
        $directory = Directory::root();

        return $gitIgnore instanceof GitIgnore && ! $gitIgnore->names($directory)
            ? Findings::of(Finding::of(
                Slug::WorkspaceNotIgnored,
                Severity::Advice,
                sprintf(self::FOUND, $directory->value()),
                self::WHY,
                sprintf(self::FIX, $directory->value()),
            ))
            : Findings::none();
    }
}
