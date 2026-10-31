<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Troubleshooting;

use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\Report\Term;
use NightWorksIO\MutationGate\Core\ThisPackage;

use function preg_match;
use function sprintf;

/**
 * The guides of the release that prints a message or renders a report, as
 * the documentation site serves them: the troubleshooting guide, which each
 * slug's link points into, and the guide to reading the report, which each
 * term's link points into, so a link never outlives what it explains
 * (ADR-0038).
 */
final readonly class Guide
{
    /** Where a guide of a version's copy of the site is, by its page, and a section in it. */
    private const string SECTION = '%s%s/guide/%s/#%s';

    /** A release's copy of the site: its minor's path. */
    private const string COPY = '/%s';

    private const string TROUBLESHOOTING = 'troubleshooting';

    private const string READING = 'reading-the-report';

    /** A released version, a pre-release included, as Composer spells it, with or without its `v`. */
    private const string RELEASED = '/^v?(?<minor>\d+\.\d+)\.\d+(?:-[0-9A-Za-z.]+)?$/D';

    /** The path of `main`'s pages, which are the site's root while no version is tagged. */
    private const string UNRELEASED = '';

    private function __construct(private string $copy)
    {
    }

    /** The guide of the version Composer installed: its minor's copy, or `main`'s for anything but a release. */
    public static function ofInstalled(string $version): self
    {
        return new self(
            preg_match(self::RELEASED, $version, $released) === 1
                ? sprintf(self::COPY, $released['minor'])
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

    /** The guide of `main`, for a gate that is not installed as a package. */
    public static function unreleased(): self
    {
        return new self(self::UNRELEASED);
    }

    public function link(Slug $slug): string
    {
        return sprintf(self::SECTION, ThisPackage::SITE, $this->copy, self::TROUBLESHOOTING, $slug->value);
    }

    /** Where the guide to reading the report says what a term means. */
    public function term(Term $term): string
    {
        return sprintf(self::SECTION, ThisPackage::SITE, $this->copy, self::READING, $term->value);
    }
}
