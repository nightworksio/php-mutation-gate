<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Troubleshooting;

use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\Report\Term;
use NightWorksIO\MutationGate\Core\ThisPackage;

use function preg_match;
use function sprintf;

/**
 * The guides of the release that prints a message or renders a report: the
 * troubleshooting guide, which each slug's link points into, and the guide
 * to reading the report, which each term's link points into, so a link never
 * outlives what it explains.
 */
final readonly class Guide
{
    /** Where a guide of a git ref is, by its file's name, and a section in it. */
    private const string SECTION = '%s/blob/%s/.docs/guide/%s.md#%s';

    private const string TROUBLESHOOTING = 'troubleshooting';

    private const string READING = 'reading-the-report';

    /** A released version, as Composer spells it, with or without its `v`. */
    private const string RELEASED = '/^v?(?<version>\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?)$/D';

    /** The branch the guide is read from when no release printed the message. */
    private const string UNRELEASED = 'main';

    private function __construct(private string $ref)
    {
    }

    /** The guide of the version Composer installed: its tag, or `main` for anything that is not a release. */
    public static function ofInstalled(string $version): self
    {
        return new self(
            preg_match(self::RELEASED, $version, $released) === 1
                ? sprintf('v%s', $released['version'])
                : self::UNRELEASED,
        );
    }

    /** The guide of the gate Composer installed, as its `installed.json` lists it; `main`'s where it lists none. */
    public static function installedIn(Installed $installed): self
    {
        $guide = self::unreleased();

        foreach ($installed->versionsOf(ThisPackage::COMPOSER) as $version) {
            $guide = self::ofInstalled($version->version());
        }

        return $guide;
    }

    /** The guide on `main`, for a gate that is not installed as a package. */
    public static function unreleased(): self
    {
        return new self(self::UNRELEASED);
    }

    public function link(Slug $slug): string
    {
        return sprintf(self::SECTION, ThisPackage::REPOSITORY, $this->ref, self::TROUBLESHOOTING, $slug->value);
    }

    /** Where the guide to reading the report says what a term means. */
    public function term(Term $term): string
    {
        return sprintf(self::SECTION, ThisPackage::REPOSITORY, $this->ref, self::READING, $term->value);
    }
}
