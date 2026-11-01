<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use DOMDocument;
use DOMElement;

use function mb_strtolower;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * The issues a test raises that fail a mutant's run as Infection starts
 * PHPUnit for it, with no test failing (ADR-0014): a test PHPUnit considers
 * risky, a warning PHP or the test raised, and a warning PHPUnit raised of a
 * test. Infection adds `failOnRisky` and `failOnWarning` to the project's
 * PHPUnit config where it sets neither (see its XmlConfigurationManipulator);
 * PHPUnit fails a run on its own warnings unless `failOnPhpunitWarning` says
 * not, and on every issue where `failOnAllIssues` says so. An attribute
 * set to what PHPUnit cannot read as `true` or `false` keeps PHPUnit's
 * default, which Infection then leaves alone.
 *
 * @internal
 */
final readonly class FailingIssues
{
    /** The attribute that fails a run on a risky test. */
    public const string RISKY = 'failOnRisky';

    /** The attribute that fails a run on a warning PHP or a test raised. */
    public const string WARNING = 'failOnWarning';

    /** The attributes Infection sets to `true` in a mutant's config where the project's sets neither. */
    public const array ADDED = [self::RISKY, self::WARNING];

    /** The attribute that fails a run on a warning PHPUnit raised of a test. */
    private const string PHPUNIT_WARNING = 'failOnPhpunitWarning';

    /** The attribute that fails a run on every issue. */
    private const string ALL = 'failOnAllIssues';

    /** The issues that fail a mutant's run, each by default as on a config that sets none of the attributes. */
    public function __construct(
        private bool $risky = true,
        private bool $warning = true,
        private bool $phpunitWarning = true,
    ) {
    }

    /** The issues that fail a mutant's run on this PHPUnit config; Infection's own where there is none. */
    public static function of(DOMDocument|NotGiven $config): self
    {
        $root = $config instanceof DOMDocument && $config->documentElement instanceof DOMElement
            ? $config->documentElement
            : NotGiven::value();
        $all = self::set($root, self::ALL, absent: false, unread: false);

        return new self(
            $all || self::set($root, self::RISKY, absent: true, unread: false),
            $all || self::set($root, self::WARNING, absent: true, unread: false),
            $all || self::set($root, self::PHPUNIT_WARNING, absent: true, unread: true),
        );
    }

    /** Whether a risky test fails the run. */
    public function risky(): bool
    {
        return $this->risky;
    }

    /** Whether a warning PHP or a test raised fails the run. */
    public function warning(): bool
    {
        return $this->warning;
    }

    /** Whether a warning PHPUnit raised of a test fails the run. */
    public function phpunitWarning(): bool
    {
        return $this->phpunitWarning;
    }

    /**
     * What an attribute of the config's root says: what a mutant's run takes
     * where the root has none, and PHPUnit's default where it says neither
     * `true` nor `false`.
     */
    private static function set(DOMElement|NotGiven $root, string $attribute, bool $absent, bool $unread): bool
    {
        if ($root instanceof NotGiven || ! $root->hasAttribute($attribute)) {
            return $absent;
        }

        return match (mb_strtolower($root->getAttribute($attribute))) {
            'true' => true,
            'false' => false,
            default => $unread,
        };
    }
}
