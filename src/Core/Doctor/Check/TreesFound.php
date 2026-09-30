<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use function count;
use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

/** The tree source finds no tree, so a run has nothing to mutate. */
final readonly class TreesFound
{
    private const string NONE = 'The tree source finds no tree to mutate.';

    private const string WHY
        = 'A tree is what the gate mutates and holds to a floor, so with none a run judges nothing.';

    private const string FIX
        = 'Name one in the config, as trees: [{path: src}], or declare a psr-4 autoload path in composer.json.';

    public static function in(Observations $observed): Findings
    {
        $trees = $observed->trees();
        $found = match (true) {
            $trees instanceof Trees => count($trees) === 0 ? self::NONE : '',
            $trees instanceof CannotJudge => $trees->why(),
            $trees instanceof Invalid => self::problems($trees),
            default => '',
        };

        return $found === ''
            ? Findings::none()
            : Findings::of(Finding::of(Slug::NoTree, Severity::WillFail, $found, self::WHY, self::FIX));
    }

    private static function problems(Invalid $invalid): string
    {
        $problems = [];

        foreach ($invalid as $problem) {
            $problems[] = $problem->message();
        }

        return implode(' ', $problems);
    }
}
