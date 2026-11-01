<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use Closure;

use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;

/**
 * What a command made, step by step, said in order.
 *
 * @internal
 */
final readonly class Said
{
    private const string LINE = "\n";

    /**
     * What was made, said in order; or, after what was, why the first that could not be made was not.
     *
     * @param string|CannotJudge|(Closure(): (string|CannotJudge)) ...$steps
     *        a closure is made only once every step before it was
     */
    public static function joined(string|CannotJudge|Closure ...$steps): string|CannotJudge
    {
        $said = [];

        foreach ($steps as $step) {
            $outcome = $step instanceof Closure ? $step() : $step;

            if ($outcome instanceof CannotJudge) {
                return CannotJudge::because(implode(self::LINE, [...$said, $outcome->why()]));
            }

            $said = $outcome === '' ? $said : [...$said, $outcome];
        }

        return implode(self::LINE, $said);
    }
}
