<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;

use function sprintf;

/**
 * The directory the gate keeps its own files under in a project, wherever nothing else is configured.
 *
 * @internal
 */
final readonly class Workspace
{
    private const string ROOT = '.mutation-gate';

    private const string LEDGER = 'ledger';

    private const string DELIVERY = 'delivery';

    private const string PHPUNIT = 'phpunit-cache';

    /** Where a runner's bridges are, under the directory. */
    private const string BRIDGES = 'mutators/%s/bridges.php';

    /** The directory itself. */
    public static function root(): Path
    {
        return Path::of(self::ROOT);
    }

    /** Where the proofs are kept when no store is chosen: `.mutation-gate/ledger`. */
    public static function ledger(): Path
    {
        return Path::of(sprintf('%s/%s', self::ROOT, self::LEDGER));
    }

    /** Where a run leaves what `deliver` sends: `.mutation-gate/delivery`. */
    public static function delivery(): Path
    {
        return Path::of(sprintf('%s/%s', self::ROOT, self::DELIVERY));
    }

    /**
     * Where every PHPUnit the gate starts keeps its caches, its test run
     * history and its code coverage cache, in place of the directory the
     * project's config names: `.mutation-gate/phpunit-cache`. A file PHPUnit writes
     * there is the gate's own, which no content key reads, so a run leaves
     * the next one's keys as they were (ADR-0007).
     */
    public static function phpUnitCache(): Path
    {
        return Path::of(sprintf('%s/%s', self::ROOT, self::PHPUNIT));
    }

    /**
     * Where under the directory the gate writes the bridges a runner makes
     * the registered mutators' mutants through (ADR-0021):
     * `mutators/<runner>/bridges.php`.
     */
    public static function bridges(BuiltinRunner $runner): Path
    {
        return Path::of(sprintf(self::BRIDGES, $runner->value));
    }
}
