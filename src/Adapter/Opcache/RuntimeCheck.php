<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Opcache;

/** A read of the PHP a program runs on that opcache answers as it compiles, by what the compiling PHP has. */
enum RuntimeCheck: string
{
    case FunctionExists = 'function_exists';
    case ExtensionLoaded = 'extension_loaded';
    case Defined = 'defined';
    case VersionId = 'PHP_VERSION_ID';
    case Os = 'PHP_OS';
    case OsFamily = 'PHP_OS_FAMILY';
    case IntSize = 'PHP_INT_SIZE';

    /** Whether the check is a call, whose name is lowercase; otherwise it is a constant, named in capitals. */
    public function isCall(): bool
    {
        return match ($this) {
            self::FunctionExists, self::ExtensionLoaded, self::Defined => true,
            self::VersionId, self::Os, self::OsFamily, self::IntSize => false,
        };
    }
}
