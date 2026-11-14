<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpStan;

/** An option the gate hands PHPStan beside `config`, from `staticCheck` (ADR-0020).
 *
 * @internal
 */
enum PhpStanOption: string
{
    /** The ini settings each PHPStan process runs under: `staticCheck.php`. */
    case Php = 'php';

    /** Whether it loads PHPStan's turbo extension where a build of it ships: `staticCheck.turbo`. */
    case Turbo = 'turbo';
}
