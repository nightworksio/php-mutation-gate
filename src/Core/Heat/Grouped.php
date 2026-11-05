<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Heat;

use Closure;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;

/**
 * Judged mutants in groups, by what a key makes of each: a file's path, or
 * the name of the function it is in; one the key names nothing for is in
 * no group (ADR-0100).
 *
 * @internal
 */
final readonly class Grouped
{
    /**
     * @param  Closure(JudgedMutant|JudgedKill): (string|Nameless) $key
     * @return array<string, JudgedMutants>                         each group, by its key, in the order first met
     */
    public static function by(JudgedMutants $mutants, Closure $key): array
    {
        $reported = [];
        $kills = [];

        foreach ($mutants as $mutant) {
            $named = $key($mutant);

            if ($named instanceof Nameless) {
                continue;
            }

            $reported[$named] ??= [];
            $kills[$named] ??= [];
            $mutant instanceof JudgedMutant ? $reported[$named][] = $mutant : $kills[$named][] = $mutant;
        }

        $groups = [];

        foreach ($reported as $named => $each) {
            $groups[$named] = JudgedMutants::of(...$each)->and(JudgedMutants::kills(...$kills[$named]));
        }

        return $groups;
    }
}
