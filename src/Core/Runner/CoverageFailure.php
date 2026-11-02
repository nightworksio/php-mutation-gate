<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_map;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Series;

use function sprintf;

/**
 * Why a runner's run of the tests under coverage gave no map: it failed, and said why.
 *
 * @internal
 */
final readonly class CoverageFailure
{
    private const string FAILED = "%s's coverage run failed. %s said:\n%s";

    private const string UNCONFIGURED
        = "%s's coverage run failed, and the project has none of its configs: no %s in its root. %s said:\n%s";

    /** A coverage run a program failed, with what the program printed. */
    public static function said(Program $program, string $output): CannotJudge
    {
        return CannotJudge::because(sprintf(self::FAILED, $program->title(), $program->title(), $output));
    }

    /** A coverage run a program failed where none of the configs it reads is there, with what it printed. */
    public static function unconfigured(Program $program, Paths $looked, string $output): CannotJudge
    {
        $names = array_map(static fn(Path $path): string => sprintf('`%s`', $path->value()), [...$looked]);

        return CannotJudge::because(
            sprintf(self::UNCONFIGURED, $program->title(), Series::or(...$names), $program->title(), $output),
        );
    }
}
