<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Testo;

use RuntimeException;

use function sprintf;

/** The gate's Testo config, which its launcher could not write to a file for Testo to read. */
final class UnwrittenConfig extends RuntimeException
{
    private const string UNWRITTEN = 'The gate cannot write its Testo config into %s.';

    public static function in(string $directory): self
    {
        return new self(sprintf(self::UNWRITTEN, $directory));
    }
}
