<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

/** The PHP the runner uses could not describe itself, and a run starts the same PHP. */
final readonly class Php
{
    private const string WHY = 'A run starts the same PHP with the same options, so it would fail the same way.';

    private const string FIX = 'Run the PHP the error names with -m by hand, and correct what stops it.';

    public static function in(Observations $observed): Findings
    {
        $php = $observed->php();

        return $php instanceof CannotJudge
            ? Findings::of(Finding::of(Slug::PhpNotRead, Severity::WillFail, $php->why(), self::WHY, self::FIX))
            : Findings::none();
    }
}
