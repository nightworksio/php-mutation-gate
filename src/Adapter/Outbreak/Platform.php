<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

use function mb_strtolower;

use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * A platform Outbreak's core is built for (ADR-0118), named as its directory
 * under `resources/outbreak` is: the OS family as PHP's `PHP_OS_FAMILY` says it,
 * lowercase, and the architecture as `php_uname('m')` says it, the names
 * different systems give one architecture read as one.
 *
 * @internal
 */
final readonly class Platform
{
    private const string NAME = '%s-%s';

    private const string WINDOWS = 'windows';

    private function __construct(private string $family, private string $architecture)
    {
    }

    /** The platform of an OS family and a machine, as PHP names them; none where no binary is built for it. */
    public static function of(string $family, string $machine): self|NotGiven
    {
        $os = match (mb_strtolower($family)) {
            'linux' => 'linux',
            'darwin' => 'darwin',
            self::WINDOWS => self::WINDOWS,
            default => '',
        };
        $architecture = match (mb_strtolower($machine)) {
            'x86_64', 'amd64' => 'x86_64',
            'aarch64', 'arm64' => 'aarch64',
            default => '',
        };

        return $os === '' || $architecture === '' ? NotGiven::value() : new self($os, $architecture);
    }

    /** The directory its binary is under. */
    public function name(): string
    {
        return sprintf(self::NAME, $this->family, $this->architecture);
    }

    public function isWindows(): bool
    {
        return $this->family === self::WINDOWS;
    }
}
