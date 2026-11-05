<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;

use NightWorksIO\MutationGate\Core\Ci\GatePin;
use NightWorksIO\MutationGate\Core\ThisPackage;

use function preg_match_all;
use function preg_quote;
use function preg_replace_callback;

use const PREG_SET_ORDER;

use function sprintf;
use function str_ends_with;

/**
 * The releases of the gate a CI definition's text pins (ADR-0083): a GitHub
 * `uses:` of its action or reusable workflow at a commit, with the version
 * in a comment where it has one, and its container image at a tag, where
 * the release has one; each stale where it is not the release installed.
 *
 * @internal
 */
final readonly class GatePins
{
    /** A `uses:` of the gate at a full commit: what it uses, the commit, and the comment after it, where it has one. */
    private const string USES = '~(%s(?:/[^@\s]*)?)@([0-9a-f]{40})(?:[ \t]*#[ \t]*[^\s]+)?~u';

    /** The gate's image at a tag. */
    private const string IMAGE = '~%s:([A-Za-z0-9._+-]+)~u';

    private const string REPOSITORY = 'nightworksio/php-mutation-gate';

    private const string USE_AT = '%s@%s';

    /**
     * Each pin of a text that is not this release, as it is written; none where the release is not known.
     *
     * @return list<string>
     */
    public static function stale(string $text, GatePin $installed): array
    {
        if (! $installed->isKnown()) {
            return [];
        }

        preg_match_all(self::uses(), $text, $uses, PREG_SET_ORDER);
        preg_match_all(self::image(), $text, $images, PREG_SET_ORDER);
        $stale = [
            ...array_map(static fn(array $use): string => $use[2] === $installed->commit() ? '' : $use[0], $uses),
            ...array_map(
                static fn(array $image): string => $image[0] === $installed->image() ? '' : $image[0],
                self::isTagged($installed) ? $images : [],
            ),
        ];

        return array_values(array_unique(array_filter($stale, static fn(string $pin): bool => $pin !== '')));
    }

    /** The text with every pin of the gate at this release. */
    public static function repinned(string $text, GatePin $installed): string
    {
        if (! $installed->isKnown()) {
            return $text;
        }

        $uses = (string) preg_replace_callback(
            self::uses(),
            static fn(array $use): string => sprintf(self::USE_AT, $use[1], $installed->pin()),
            $text,
        );

        return self::isTagged($installed)
            ? (string) preg_replace_callback(self::image(), $installed->image(...), $uses)
            : $uses;
    }

    /** Whether the release has an image tag: a branch Composer installed has none. */
    private static function isTagged(GatePin $installed): bool
    {
        return ! str_ends_with($installed->image(), GatePin::UNKNOWN_VERSION);
    }

    private static function uses(): string
    {
        return sprintf(self::USES, preg_quote(self::REPOSITORY, '~'));
    }

    private static function image(): string
    {
        return sprintf(self::IMAGE, preg_quote(ThisPackage::IMAGE, '~'));
    }
}
