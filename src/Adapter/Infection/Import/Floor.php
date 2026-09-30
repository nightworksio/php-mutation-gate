<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection\Import;

use NightWorksIO\MutationGate\Core\Config\Floors;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Import\Carried;
use NightWorksIO\MutationGate\Core\Import\Import;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor as TreeFloor;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Score\Undeclared;

use function sprintf;

/**
 * Infection's `minMsi` and `minCoveredMsi` as the floor every imported tree
 * declares (ADR-0016, decision 2): truncated to two decimals, as the policy
 * minimum rather than a baseline, since nothing was measured. `minCoveredMsi`
 * leaves uncovered mutants out of the score, and is the floor where `minMsi`
 * is not set.
 */
final readonly class Floor
{
    private const string EVERY_TREE = 'the floor of every tree, %s';

    private const string UNCOVERED = 'uncovered: exclude';

    private const string UNCOVERED_AND_FLOOR = 'uncovered: exclude, and the floor of every tree, %s';

    private const string NONE = 'a floor of 0 holds a tree to nothing';

    private const string NOT_A_PERCENTAGE = '%s is not a percentage';

    /** The floor the config sets every tree, or none, where it sets no floor above 0. */
    public static function of(Node $settings): TreeFloor|Undeclared
    {
        $msi = $settings->field(Mapped::MinMsi->value);

        return self::floorOf($msi->isPresent() ? $msi : $settings->field(Mapped::MinCoveredMsi->value));
    }

    /** What became of `minMsi` and `minCoveredMsi`, and `uncovered: exclude` where the latter is set. */
    public static function imported(Node $settings): Import
    {
        $msi = $settings->field(Mapped::MinMsi->value);
        $covered = $settings->field(Mapped::MinCoveredMsi->value);
        $import = $msi->isPresent() ? self::said(Mapped::MinMsi->value, $msi, self::EVERY_TREE) : Import::none();

        if (! $covered->isPresent()) {
            return $import;
        }

        $floor = self::floorOf($covered);
        $carried = $msi->isPresent() || ! $floor instanceof TreeFloor
            ? Carried::imported(Mapped::MinCoveredMsi->value, self::UNCOVERED)
            : Carried::imported(Mapped::MinCoveredMsi->value, sprintf(self::UNCOVERED_AND_FLOOR, self::points($floor)));

        return $import->and(Import::of(Layer::of(Floors::of(uncovered: Uncovered::Exclude)), $carried));
    }

    /** What became of a key that sets a floor: imported as the floor, or dropped where it holds none. */
    private static function said(string $key, Node $value, string $as): Import
    {
        $floor = self::floorOf($value);
        $carried = match (true) {
            $floor instanceof TreeFloor => Carried::imported($key, sprintf($as, self::points($floor))),
            self::isNumber($value) && $value->number() === 0.0 => Carried::dropped($key, self::NONE),
            default => Carried::dropped($key, sprintf(self::NOT_A_PERCENTAGE, $value->json())),
        };

        return Import::of(Layer::none(), $carried);
    }

    private static function floorOf(Node $value): TreeFloor|Undeclared
    {
        $percentage = self::isNumber($value) ? Percentage::parse($value->number()) : Undeclared::floor();

        return $percentage instanceof Percentage && $percentage->hundredths() > 0
            ? TreeFloor::ofHundredths($percentage->hundredths())
            : Undeclared::floor();
    }

    private static function isNumber(Node $value): bool
    {
        return $value->kind() === Kind::Integer || $value->kind() === Kind::Number;
    }

    private static function points(TreeFloor $floor): string
    {
        return Percentage::points($floor->hundredths());
    }
}
