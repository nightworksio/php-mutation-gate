<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Turbo;

/** A processor the helper is built for, as `php_uname('m')` names it on each system, in lower case. */
enum Machine: string
{
    case X8664 = 'x86_64';

    case Amd64 = 'amd64';

    case Arm64 = 'arm64';

    case Aarch64 = 'aarch64';

    /** Whether it is a 64-bit ARM processor, by either of its names. */
    public function isArm(): bool
    {
        return $this === self::Arm64 || $this === self::Aarch64;
    }
}
