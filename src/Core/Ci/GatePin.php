<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\OwnArchive;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\ThisPackage;

use function preg_match;
use function sprintf;

/**
 * The gate's own release as a CI definition pins it (ADR-0015):
 * the commit Composer installed it from, with its version, read from the
 * project's `vendor/composer/installed.json`; or, under the gate's PHAR, the
 * commit and version built into it (ADR-0022). A gate Composer did not
 * install, such as its own checkout, pins nothing, and says so.
 *
 * @internal
 */
final readonly class GatePin
{
    /** What stands for the commit where none is known, for the person to replace. */
    public const string UNKNOWN_COMMIT = '<the commit of a release>';

    /** What stands for the version where none is known. */
    public const string UNKNOWN_VERSION = '<its version>';

    /** A full git commit, which a definition can pin. */
    private const string COMMIT = '/^[0-9a-f]{40}$/D';

    /** A version as Composer spells one, which a comment can hold. */
    private const string VERSION = '/^[A-Za-z0-9._+-]+$/D';

    private function __construct(private string $commit, private string $version, private bool $release)
    {
    }

    /**
     * The gate as Composer lists it; unknown where Composer lists nothing that can be read, does not list it, or
     * names no full commit, as a path repository does, or a version with more than letters, digits and `.`, `_`, `+`
     * and `-` in it.
     */
    public static function in(Installed|CannotJudge $installed): self
    {
        $versions = $installed instanceof Installed ? $installed->versionsOf(ThisPackage::COMPOSER) : Versions::none();

        foreach ($versions as $version) {
            $pin = self::of($version);

            if ($pin->isKnown()) {
                return $pin;
            }
        }

        return self::unknown();
    }

    /**
     * The gate at this version, as its PHAR has it built in; unknown where it names no full commit, or a version
     * with more than letters, digits and `.`, `_`, `+` and `-` in it.
     */
    public static function of(Version $version): self
    {
        $pinned = preg_match(self::COMMIT, $version->reference()) === 1
            && preg_match(self::VERSION, $version->version()) === 1;

        return $pinned ? new self($version->reference(), $version->version(), $version->isRelease()) : self::unknown();
    }

    /**
     * The gate as it runs: the version built into its PHAR, where it runs from one, else as Composer lists it in the
     * project (see in).
     */
    public static function running(OwnArchive|NotGiven $archive, Version $built, Installed|CannotJudge $installed): self
    {
        return $archive instanceof OwnArchive ? self::of($built) : self::in($installed);
    }

    public static function unknown(): self
    {
        return new self(self::UNKNOWN_COMMIT, self::UNKNOWN_VERSION, release: false);
    }

    public function commit(): string
    {
        return $this->commit;
    }

    public function version(): string
    {
        return $this->version;
    }

    /**
     * The gate as a definition pins it: its commit, with the version in a comment where the version is a
     * release, whose tag names that commit; a branch Composer installed, such as `dev-main`, names no tag.
     */
    public function pin(): string
    {
        return $this->release ? sprintf('%s # %s', $this->commit, $this->version) : $this->commit;
    }

    /** Whether the commit is known, so a definition that names it runs as written. */
    public function isKnown(): bool
    {
        return $this->commit !== self::UNKNOWN_COMMIT;
    }
}
