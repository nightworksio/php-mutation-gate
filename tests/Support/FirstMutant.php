<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use LogicException;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;

/** The first mutant a run made, for a test that goes on to run it again. */
final class FirstMutant
{
    public static function of(MutationResult|CannotJudge $result): Mutant
    {
        $mutants = $result instanceof MutationResult ? [...$result->mutants()] : [];

        return $mutants[0] ?? throw new LogicException('The run made no mutant.');
    }
}
