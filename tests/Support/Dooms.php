<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use LogicException;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;

use function sprintf;

/** The survivors the tests of a doomed run judge. */
final readonly class Dooms
{
    /** The fixture's one survivor of a file, as the shard's runner reports it. */
    public static function survivor(string $file): Mutant
    {
        foreach (Flows::mutantsOf($file) as $mutant) {
            if ($mutant->status() === MutantStatus::Survived) {
                return $mutant;
            }
        }

        throw new LogicException(sprintf('No survivor in %s.', $file));
    }
}
