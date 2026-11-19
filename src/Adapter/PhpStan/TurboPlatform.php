<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpStan;

use function glob;
use function in_array;
use function mb_strtolower;

use NightWorksIO\MutationGate\Core\Local\System;

use function php_uname;
use function sprintf;

/**
 * The name PHPStan gives a platform among the builds of its turbo
 * extension: by its machine, its OS family and, on Linux, its C library.
 *
 * @internal
 */
final readonly class TurboPlatform
{
    /** The platforms PHPStan ships a build for. */
    private const array BUILT = [
        'linux-gnu-x86_64',
        'linux-gnu-arm64',
        'linux-musl-x86_64',
        'linux-musl-arm64',
        'macos-arm64',
        'windows-x86_64',
    ];

    /** This machine's; none where PHPStan ships no build for it. */
    public static function here(): string
    {
        $system = System::here();

        return self::named(php_uname('m'), $system, $system === System::Linux && self::isMusl());
    }

    /**
     * A platform's, by its machine as `uname -m` names it, its OS family
     * and whether it links against musl; none where PHPStan ships no build
     * for it.
     */
    public static function named(string $uname, System $system, bool $musl): string
    {
        $machine = self::machine($uname);
        $platform = match ($system) {
            System::Darwin => sprintf('macos-%s', $machine),
            System::Windows => sprintf('windows-%s', $machine),
            System::Linux => sprintf('linux-%s-%s', $musl ? 'musl' : 'gnu', $machine),
            System::Other => '',
        };

        return in_array($platform, self::BUILT, strict: true) ? $platform : '';
    }

    /** A machine as PHPStan names it among its builds, by `uname -m`'s name for it; none for any other. */
    private static function machine(string $uname): string
    {
        return match (mb_strtolower($uname)) {
            'x86_64', 'amd64' => 'x86_64',
            'aarch64', 'arm64' => 'arm64',
            default => '',
        };
    }

    /** Whether this Linux links against musl, as Alpine's does, by its loader. */
    private static function isMusl(): bool
    {
        $loaders = glob('/lib/ld-musl-*');

        return $loaders !== false && $loaders !== [];
    }
}
