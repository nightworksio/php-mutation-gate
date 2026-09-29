<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function sprintf;
use function str_starts_with;

/**
 * The layers of ADR-0001, each a namespace under `NightWorksIO\MutationGate` and a
 * directory under `src`.
 */
enum Layer: string
{
    case Core = 'Core';
    case Attribute = 'Attribute';
    case Port = 'Port';
    case Config = 'Config';
    case Extension = 'Extension';
    case Adapter = 'Adapter';
    case Cli = 'Cli';

    public const string ROOT = 'NightWorksIO\MutationGate';

    /** Whether a fully-qualified class name is in this layer. */
    public function holds(string $class): bool
    {
        return str_starts_with($class, sprintf('%s\\', $this->namespace()));
    }

    /** Whether this layer is part of the package's public API (ADR-0001). */
    public function isPublic(): bool
    {
        return match ($this) {
            self::Attribute, self::Port, self::Config, self::Extension => true,
            self::Core, self::Adapter, self::Cli => false,
        };
    }

    public function namespace(): string
    {
        return sprintf('%s\\%s', self::ROOT, $this->value);
    }

    public function directory(): string
    {
        return sprintf('src/%s', $this->value);
    }
}
