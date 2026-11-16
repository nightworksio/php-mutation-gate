<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Bench\Core\Coverage;

use function intdiv;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\MapText;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

use Tally\Attribute\DataProvider;
use Tally\Attribute\Linear;
use Tally\Attribute\Warmup;

/** Joining two coverage maps, as the gate's own map joins the runner contract's, and writing a map out. */
final class CoverageMapBench
{
    /** @return iterable<string, array{int, CoverageMap, CoverageMap}> */
    public static function maps(): iterable
    {
        foreach ([200, 800, 3200] as $lines) {
            yield sprintf('lines-%d', $lines) => [$lines, self::mapOf($lines, 'Unit'), self::mapOf($lines, 'Contract')];
        }
    }

    #[DataProvider('maps')]
    #[Linear('lines')]
    #[Warmup(1)]
    public function benchJoined(int $lines, CoverageMap $suite, CoverageMap $contract): void
    {
        $suite->and($contract);
    }

    #[DataProvider('maps')]
    #[Linear('lines')]
    #[Warmup(1)]
    public function benchWritten(int $lines, CoverageMap $suite, CoverageMap $contract): void
    {
        MapText::of($suite)->written(Unplaced::map(), new NotGiven());
    }

    /** A map of so many lines over files of forty lines, each run by three tests of the suite named. */
    private static function mapOf(int $lines, string $suite): CoverageMap
    {
        $covered = [];

        for ($at = 0; $at < $lines; $at++) {
            $file = intdiv($at, 40);
            $covered[] = CoveredLine::of(
                Path::of(sprintf('src/File%d.php', $file)),
                1 + $at % 40,
                sprintf('%s\\File%dTest::first', $suite, $file),
                sprintf('%s\\File%dTest::second', $suite, $file),
                sprintf('%s\\SharedTest::line%d', $suite, $at % 7),
            );
        }

        return CoverageMap::of(...$covered);
    }
}
