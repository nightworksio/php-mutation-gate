<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Bench\Mutator\Engine;

use function implode;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Tests\Support\DefaultEngine;

use function sprintf;

use Tally\Attribute\DataProvider;
use Tally\Attribute\Linear;
use Tally\Attribute\Warmup;

/**
 * The default mutators making the mutants of a file, and counting its sites,
 * which every plan does for every unit. Each mutant made holds only its
 * change, printed alone, so making them grows with the file.
 */
final class EngineBench
{
    /** @return iterable<string, array{int, Engine, Contents}> */
    public static function files(): iterable
    {
        foreach ([10, 40, 160] as $methods) {
            yield sprintf('methods-%d', $methods) => [$methods, DefaultEngine::made(), self::code($methods)];
        }
    }

    #[DataProvider('files')]
    #[Linear('methods')]
    #[Warmup(1)]
    public function benchMutantsOf(int $methods, Engine $engine, Contents $code): void
    {
        $engine->mutantsOf(Path::of('src/Ledger.php'), $code);
    }

    #[DataProvider('files')]
    #[Linear('methods')]
    #[Warmup(1)]
    public function benchSitesOf(int $methods, Engine $engine, Contents $code): void
    {
        $engine->sitesOf(Path::of('src/Ledger.php'), $code);
    }

    /** A class of so many methods, each a loop of comparisons and arithmetic the default mutators all reach. */
    private static function code(int $methods): Contents
    {
        $bodies = [];

        for ($at = 0; $at < $methods; $at++) {
            $bodies[] = sprintf(<<<'PHP'
                    public function total%1$d(array $items, int $limit): int
                    {
                        $sum = 0;
                        foreach ($items as $item) {
                            if ($item > $limit && $sum !== %1$d) {
                                $sum += $item * 2 - 1;
                            }
                        }
                        return $sum >= 0 ? $sum : -$sum;
                    }
                PHP, $at);
        }

        return Contents::of(sprintf("<?php\n\nfinal class Ledger\n{\n%s\n}\n", implode("\n\n", $bodies)));
    }
}
