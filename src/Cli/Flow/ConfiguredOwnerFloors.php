<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Config\Table;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Owner\Owner;
use NightWorksIO\MutationGate\Core\Owner\OwnerFloor;
use NightWorksIO\MutationGate\Core\Owner\OwnerFloors;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function sprintf;

/**
 * The owners `owners.floors` holds to floors (ADR-0022): each key an owner a
 * CODEOWNERS rule can write, with its floor; and a warning for each key that
 * is none, which holds no code to anything.
 */
final readonly class ConfiguredOwnerFloors
{
    private const string NO_OWNER = <<<'SAID'
        owners.floors names %s, which no CODEOWNERS rule can name as an owner, so it holds no code to its floor.
        SAID;

    /** The floor of each owner the config names, by an owner a CODEOWNERS rule can write. */
    public static function of(Table $floors): OwnerFloors
    {
        $owned = [];

        foreach ($floors as $named => $floor) {
            $owner = Owner::parse($named);
            $owned = $owner instanceof Owner ? [...$owned, OwnerFloor::of($owner, Floor::of($floor))] : $owned;
        }

        return OwnerFloors::of(...$owned);
    }

    /** That each key no CODEOWNERS rule can write holds nothing to its floor. */
    public static function unnamed(Table $floors): Warnings
    {
        $warnings = Warnings::none();

        foreach ($floors as $named => $floor) {
            $warnings = Owner::parse($named) instanceof Owner
                ? $warnings
                : $warnings->with(Warning::that(sprintf(self::NO_OWNER, Fit::plain($named))));
        }

        return $warnings;
    }
}
