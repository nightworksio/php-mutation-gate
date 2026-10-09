<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;

/** What a command made, step by step, said in order. */
final readonly class Said
{
    private const string LINE = "\n";

    /** What was made, said in order; or, after what was, why the first that could not be made was not. */
    public static function joined(string|CannotJudge ...$outcomes): string|CannotJudge
    {
        $said = [];

        foreach ($outcomes as $outcome) {
            if ($outcome instanceof CannotJudge) {
                return CannotJudge::because(implode(self::LINE, [...$said, $outcome->why()]));
            }

            $said = $outcome === '' ? $said : [...$said, $outcome];
        }

        return implode(self::LINE, $said);
    }
}
