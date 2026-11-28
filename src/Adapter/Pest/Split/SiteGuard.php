<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_keys;
use function array_map;

use NightWorksIO\MutationGate\Adapter\Pest\SchemaSwitch;

/**
 * The guard a split stream keeps in front of its schemata's switches
 * (ADR-0204): every site of its plan undecided as it starts, every other
 * site of a file it plans decided, and each site decided once its mutant is
 * killed.
 *
 * @internal
 */
final readonly class SiteGuard
{
    /** @param array<string, array{string, int}> $sites each planned mutant's file key and site id, by the mutant */
    private function __construct(private array $sites)
    {
    }

    /** The guard of this plan, put in place. */
    public static function of(StreamPlan $plan): self
    {
        $sites = [];

        foreach ($plan->sites as $file => $planned) {
            foreach ($planned as $id => [$mutant]) {
                $sites[$mutant] = [$file, $id];
            }
        }

        SchemaSwitch::guarding(array_map(array_keys(...), $plan->sites));

        return new self($sites);
    }

    /** Each site of these killed mutants decided. */
    public function killed(string ...$mutants): void
    {
        foreach ($mutants as $mutant) {
            [$file, $id] = $this->sites[$mutant];
            SchemaSwitch::decided($file, $id);
        }
    }
}
