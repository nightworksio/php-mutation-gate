<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\CannotJudge;

use function sprintf;

/** Why a runner's run of the tests under coverage gave no map: it failed, and said why. */
final readonly class CoverageFailure
{
    private const string FAILED = "%s's coverage run failed. %s said:\n%s";

    /** A coverage run a program failed, with what the program printed. */
    public static function said(Program $program, string $output): CannotJudge
    {
        return CannotJudge::because(sprintf(self::FAILED, $program->title(), $program->title(), $output));
    }
}
