<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * What a program declares, which its op arrays do not show (ADR-0013): the
 * program printed with every function's body taken out. Two programs that
 * print alike declare the same.
 *
 * @internal
 */
interface PrintsDeclarations
{
    /** The program's declarations, printed; none where it does not parse, which proves nothing. */
    public function declarations(Contents $program): string|NotGiven;
}
