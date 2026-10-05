<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;

use function sprintf;

/** The directory the gate keeps its own files under in a project, wherever nothing else is configured. */
final readonly class Workspace
{
    private const string ROOT = '.mutation-gate';

    private const string LEDGER = 'ledger';

    private const string DELIVERY = 'delivery';

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
     * Where under the directory the gate writes the bridges a runner makes
     * the registered mutators' mutants through (ADR-0021):
     * `mutators/<runner>/bridges.php`.
     */
    public static function bridges(BuiltinRunner $runner): Path
    {
        return Path::of(sprintf(self::BRIDGES, $runner->value));
    }
}
