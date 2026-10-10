<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Turbo;

use function mb_strtolower;

use NightWorksIO\MutationGate\Core\Runner\OsFamily;
use NightWorksIO\MutationGate\Core\Turbo\NotAccelerated;
use NightWorksIO\MutationGate\Core\Turbo\Protocol;

use function sprintf;

/**
 * A platform the helper's package carries a binary for, named as its
 * directory under `bin/` (ADR-0029). One Linux binary per processor serves
 * glibc and musl alike, as it links statically.
 */
enum Platform: string
{
    case LinuxX64 = 'linux-x86_64';

    case LinuxArm64 = 'linux-arm64';

    case MacX64 = 'macos-x86_64';

    case MacArm64 = 'macos-arm64';

    case WindowsX64 = 'windows-x86_64';

    private const string NONE = 'The helper is built for no %s on %s, so the gate works in its own PHP.';

    private const string NONE_FOR = 'The helper is built for nothing on %s, so the gate works in its own PHP.';

    private const string EXECUTABLE = '%s.exe';

    /** The platform PHP runs on, as `PHP_OS_FAMILY` and `php_uname('m')` name it; or why the helper has none. */
    public static function of(string $family, string $machine): self|NotAccelerated
    {
        $system = OsFamily::tryFrom($family);
        $processor = Machine::tryFrom(mb_strtolower($machine));

        return match (true) {
            $system === null, $processor === null => NotAccelerated::because(sprintf(self::NONE, $machine, $family)),
            $system === OsFamily::Windows && $processor->isArm() => NotAccelerated::because(
                sprintf(self::NONE, $machine, $family),
            ),
            default => self::on($system, $processor->isArm()),
        };
    }

    /** The binary's file name on this platform. */
    public function binary(): string
    {
        return $this === self::WindowsX64 ? sprintf(self::EXECUTABLE, Protocol::HELPER) : Protocol::HELPER;
    }

    private static function on(OsFamily $system, bool $arm): self|NotAccelerated
    {
        return match ($system) {
            OsFamily::Linux => $arm ? self::LinuxArm64 : self::LinuxX64,
            OsFamily::Darwin => $arm ? self::MacArm64 : self::MacX64,
            OsFamily::Windows => self::WindowsX64,
            OsFamily::Bsd, OsFamily::Solaris, OsFamily::Unknown => NotAccelerated::because(
                sprintf(self::NONE_FOR, $system->value),
            ),
        };
    }
}
