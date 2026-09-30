<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Unit;

use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;

use function str_ends_with;

/**
 * Every unit of the trees the gate mutates: each held path in such a tree,
 * then each PHP file of such a tree that no held path holds. A path whose
 * innermost tree is exempt is not mutated.
 */
final readonly class TreeUnits
{
    private const string PHP = '.php';

    public static function of(Trees $trees, Fingerprints $files, Units $held): Units
    {
        $units = Units::none();

        foreach ($held as $unit) {
            $units = self::isMutated($trees, $unit) ? $units->with($unit) : $units;
        }

        foreach ($files as $file) {
            $unit = Unit::file($file->path());
            $mutated = str_ends_with($file->path()->value(), self::PHP)
                && self::isMutated($trees, $unit)
                && ! self::isHeld($held, $unit);
            $units = $mutated ? $units->with($unit) : $units;
        }

        return $units;
    }

    private static function isMutated(Trees $trees, Unit $unit): bool
    {
        $tree = $trees->holding($unit->path());

        return $tree instanceof Tree && ! $tree->declared() instanceof Exempt;
    }

    private static function isHeld(Units $held, Unit $unit): bool
    {
        foreach ($held as $holding) {
            if ($unit->path()->within($holding->path())) {
                return true;
            }
        }

        return false;
    }
}
