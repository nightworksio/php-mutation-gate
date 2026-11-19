<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Native\MutantPlacement;
use NightWorksIO\MutationGate\Core\Native\PlacedMutant;
use NightWorksIO\MutationGate\Core\Native\Selection;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\OwnArchive;

use function sprintf;
use function str_ends_with;

/** A placement that cannot place a mutant of a file, nor make or write a file of a run, whose name ends so, and places as another does otherwise. */
final readonly class RefusingPlacement implements MutantPlacement
{
    /** Why a refused file cannot be made, by its name. */
    public const string REFUSED = '%s cannot be made here.';

    public function __construct(private MutantPlacement $placement, private string $ending)
    {
    }

    public function override(OwnArchive|NotGiven $archive): string|CannotJudge
    {
        return $this->placement->override($archive);
    }

    public function startingPhp(string $override): array
    {
        return $this->placement->startingPhp($override);
    }

    public function serving(string $original, string $mutated, string $guard): string
    {
        return $this->placement->serving($original, $mutated, $guard);
    }

    public function placed(
        Mutant $mutant,
        Contents $code,
        Selection $selection,
        string $part = '',
    ): PlacedMutant|CannotJudge {
        $file = $mutant->location()->file()->value();

        return str_ends_with($file, $this->ending)
            ? CannotJudge::because(sprintf(self::REFUSED, $file))
            : $this->placement->placed($mutant, $code, $selection, $part);
    }

    public function fresh(string $name): string|CannotJudge
    {
        return str_ends_with($name, $this->ending) ? CannotJudge::because(sprintf(self::REFUSED, $name)) : $this->placement->fresh($name);
    }

    public function written(string $name, string $contents): string|CannotJudge
    {
        return str_ends_with($name, $this->ending)
            ? CannotJudge::because(sprintf(self::REFUSED, $name))
            : $this->placement->written($name, $contents);
    }
}
