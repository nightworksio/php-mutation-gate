<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function str_ends_with;
use function str_starts_with;

/** The exact installed version of one package a runner drives, and its source reference. */
final readonly class Version
{
    /** How Composer names a branch it installed: `dev-main`, or `2.x-dev` for one it aliases. */
    private const string DEV = 'dev-';

    private const string BRANCH = '-dev';

    private function __construct(private string $package, private string $version, private string $reference)
    {
    }

    public static function of(string $package, string $version, string $reference): self
    {
        return new self($package, $version, $reference);
    }

    public function package(): string
    {
        return $this->package;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function reference(): string
    {
        return $this->reference;
    }

    /** Whether Composer installed a release, whose version is a tag, rather than a branch: `dev-main` or `2.x-dev`. */
    public function isRelease(): bool
    {
        return $this->version !== ''
            && ! str_starts_with($this->version, self::DEV)
            && ! str_ends_with($this->version, self::BRANCH);
    }
}
