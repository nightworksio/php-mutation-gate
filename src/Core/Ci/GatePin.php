<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\ThisPackage;

/**
 * The gate's own release as a CI definition pins it (ADR-0015 decision 14):
 * the commit Composer installed it from, with its version, read from the
 * project's `vendor/composer/installed.json`. A gate Composer did not
 * install, such as its own checkout, pins nothing, and says so.
 */
final readonly class GatePin
{
    /** What stands for the commit where none is known, for the person to replace. */
    public const string UNKNOWN_COMMIT = '<the commit of a release>';

    /** What stands for the version where none is known. */
    public const string UNKNOWN_VERSION = '<its version>';

    private function __construct(private string $commit, private string $version)
    {
    }

    /** The gate as Composer lists it; unknown where Composer does not list it or names no commit. */
    public static function in(Installed $installed): self
    {
        foreach ($installed->versionsOf(ThisPackage::COMPOSER) as $version) {
            if ($version->reference() !== '') {
                return new self($version->reference(), $version->version());
            }
        }

        return self::unknown();
    }

    public static function unknown(): self
    {
        return new self(self::UNKNOWN_COMMIT, self::UNKNOWN_VERSION);
    }

    public function commit(): string
    {
        return $this->commit;
    }

    public function version(): string
    {
        return $this->version;
    }

    /** Whether the commit is known, so a definition that names it runs as written. */
    public function isKnown(): bool
    {
        return $this->commit !== self::UNKNOWN_COMMIT;
    }
}
