<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Ci\CiRun;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Time\Instant;

use function sprintf;

/**
 * The run that establishes a proof, as the ledger names it: as its CI numbers
 * it (`CiRun::id()`), and otherwise `local:<time of the run>`. Every proof it
 * establishes records the base its keys were built on.
 */
final readonly class RunName
{
    public static function of(Variables $environment, Instant $at, Digest $base): Run
    {
        $run = CiRun::read($environment);
        $id = $run instanceof CiRun ? $run->id() : '';

        return Run::of($id === '' ? sprintf('local:%s', $at->value()) : $id, $at, $base);
    }
}
