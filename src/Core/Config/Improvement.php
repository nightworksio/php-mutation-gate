<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** What a score above its committed floor does in a pull request (ADR-0003). */
enum Improvement: string
{
    /** It fails until the raised floor is committed. */
    case Require = 'require';

    /** It passes, and the summary shows the command that raises the floor. */
    case Report = 'report';
}
