<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cluster;

use function array_filter;
use function array_values;
use function count;

use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Php\Functions;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;

use function sort;

/**
 * Survivors of one file in one named function, of one family, judged alike
 * by exactly the same tests, such as three removed calls none of which is
 * asserted. A mutant in no function, or of a mutator with no family, is in no
 * gap.
 */
final readonly class Gaps
{
    /**
     * @param  list<JudgedMutant>       $mutants
     * @return list<list<JudgedMutant>> each group of two or more
     */
    public static function among(array $mutants, Functions $functions): array
    {
        $groups = [];

        foreach ($mutants as $judged) {
            $key = self::keyOf($judged, $functions);

            if ($key !== '') {
                $groups[$key][] = $judged;
            }
        }

        return array_values(array_filter($groups, static fn(array $members): bool => count($members) > 1));
    }

    /** What the members of one gap share: the function, the family, the judgement and the tests; nothing for none. */
    private static function keyOf(JudgedMutant $judged, Functions $functions): string
    {
        $mutant = $judged->mutant();
        $function = $functions->startAround($mutant->location()->start());
        $family = $mutant->mutation()->family();

        if ($function instanceof Nameless || ! $family->isKind()) {
            return '';
        }

        $tests = [];

        foreach ($judged->tests() as $test) {
            $tests[] = $test->value();
        }

        sort($tests);

        return JsonText::compact([$function->number(), $family->value, $judged->judgement()->value, $tests]);
    }
}
