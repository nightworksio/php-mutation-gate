<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Testo;

use InvalidArgumentException;

use function sprintf;

use Testo\Application\Config\ApplicationConfig;

/** A project's `testo.php` that returns no Testo config, which Testo refuses as it refuses its own. */
final class NotATestoConfig extends InvalidArgumentException
{
    private const string NOT_A_CONFIG = 'Configuration file %s must return an instance of %s.';

    public static function at(string $file): self
    {
        return new self(sprintf(self::NOT_A_CONFIG, $file, ApplicationConfig::class));
    }
}
