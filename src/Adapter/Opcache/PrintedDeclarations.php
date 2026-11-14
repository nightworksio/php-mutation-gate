<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Opcache;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Mutant\PrintsDeclarations;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * Each program's declarations as php-parser prints them with every body
 * taken out (see Declarations).
 *
 * @internal
 */
final readonly class PrintedDeclarations implements PrintsDeclarations
{
    public function declarations(Contents $program): string|NotGiven
    {
        $declared = Declarations::of($program);

        return $declared instanceof Declarations ? $declared->printed() : NotGiven::value();
    }
}
