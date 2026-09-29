<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Discovery;

/** An extension class a package names in its `composer.json`. */
final readonly class Declared
{
    /** @param string $origin the package that names it */
    public function __construct(public string $origin, public string $class)
    {
    }
}
