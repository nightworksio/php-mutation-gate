<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use function array_filter;
use function implode;

use NightWorksIO\MutationGate\Core\Doctor\ComposerSetup;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/**
 * A path repository that copies a tree into the vendor directory, so the
 * tests load the copy and no mutant of the tree is ever run.
 *
 * @internal
 */
final readonly class MirroredRepository
{
    private const string HOLDS
        = 'composer.json copies %s into the vendor directory, "symlink": false, with the tree %s';

    private const string WHY
        = 'The tests load the copy, so a mutant of the tree changes code no test runs, and every one survives.';

    private const string FIX
        = 'Remove "symlink": false from that path repository\'s options, then run composer update for its packages.';

    public static function in(Observations $observed): Findings
    {
        $composer = $observed->files()->composer();
        $trees = $observed->trees();

        if (! $composer instanceof ComposerSetup || ! $trees instanceof Trees) {
            return Findings::none();
        }

        $found = [];

        foreach ($composer->mirrored() as $directory) {
            $inside = array_filter([...$trees], static fn(Tree $tree): bool => $tree->path()->within($directory));

            foreach ($inside as $tree) {
                $found[] = sprintf(self::HOLDS, $directory->value(), $tree->path()->value());
            }
        }

        return $found === []
            ? Findings::none()
            : Findings::of(Finding::of(
                Slug::MirroredPathRepository,
                Severity::WillFail,
                sprintf('%s.', implode('; ', $found)),
                self::WHY,
                self::FIX,
            ));
    }
}
