<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules\OneHome;

use function explode;

/** A value, the constant or enum case that holds it, and where that is declared. */
final readonly class Home
{
    public function __construct(
        public string $value,
        public string $name,
        public string $file,
        public int $line,
        public bool $isCase,
    ) {
    }

    /** The class that declares it. */
    public function class(): string
    {
        return explode('::', $this->name)[0];
    }
}
