<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\IdPrefix;
use NightWorksIO\MutationGate\Core\Proof\Ambiguous;
use NightWorksIO\MutationGate\Core\Proof\NoRecord;
use NightWorksIO\MutationGate\Core\Proof\Recorded;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Runner\Reproducible;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * `reproduce`: the newest record the readable ledgers hold of a mutant, its
 * own scope's first, run again on its own by the tests that judge its unit
 * now, allowed the configured cap (ADR-0004 decision 6). A unit no holding
 * names is judged by the whole suite, as a run judges it.
 */
final readonly class Reproducing
{
    public function __construct(private Adapters $adapters, private Settings $settings)
    {
    }

    public function reproduce(IdPrefix $sought): Reproduced|NoRecord|Ambiguous|CannotJudge
    {
        $inventory = Inventory::of($this->adapters, $this->settings);

        if ($inventory instanceof CannotJudge) {
            return $inventory;
        }

        $ledgers = Ledgers::read($this->adapters->proofs, $inventory->standing, Writing::Never);
        $newest = $ledgers->records($sought)->newest();

        return $newest instanceof Recorded ? $this->again($newest, $inventory->units) : $newest;
    }

    private function again(Recorded $recorded, Units $units): Reproduced|CannotJudge
    {
        $judgedBy = $this->judgedBy($recorded->proof()->unit(), $units);
        $now = $this->adapters->runner->reproduce(
            Reproducible::of($recorded->mutant()),
            $judgedBy,
            $this->settings->triage()->limit(),
            $this->adapters->withheld,
        );

        return $now instanceof CannotJudge ? $now : new Reproduced($recorded, $judgedBy, $now);
    }

    private function judgedBy(Path $unit, Units $units): WholeSuite|Group|Filter
    {
        $judgedBy = WholeSuite::tests();

        foreach ($units as $each) {
            $judgedBy = $each->path()->equals($unit) ? $each->judgedBy() : $judgedBy;
        }

        return $judgedBy;
    }
}
