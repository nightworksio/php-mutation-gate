<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Bench\Adapter\Outbreak;

use function json_encode;

use NightWorksIO\MutationGate\Adapter\Outbreak\ReportAnswer;
use NightWorksIO\MutationGate\Core\Coverage\RecordedBeside;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Runner\RanReport;

use function sprintf;
use function strlen;
use function substr;

use Tally\Attribute\DataProvider;
use Tally\Attribute\Linear;
use Tally\Attribute\Warmup;

/** Reading the outline outbreak.so answers of a suite's coverage report, which every run on a map from the binary does. */
final class ReportAnswerBench
{
    private const int FILES = 200;

    /** @return iterable<string, array{int, Node, RanReport}> */
    public static function outlines(): iterable
    {
        foreach ([100, 400, 1600] as $tests) {
            yield sprintf('tests-%d', $tests) => [
                $tests,
                self::answerOf($tests),
                RanReport::at(
                    '/p/coverage.php',
                    4,
                    RecordedBeside::nothing(),
                    static fn(string $file): Path => Path::of(substr($file, strlen('/p/'))),
                ),
            ];
        }
    }

    #[DataProvider('outlines')]
    #[Linear('tests')]
    #[Warmup(1)]
    public function benchOutline(int $tests, Node $said, RanReport $report): void
    {
        ReportAnswer::outline($said, $report);
    }

    /** The answer of an outline of so many tests over two hundred files, each test at eight of them. */
    private static function answerOf(int $tests): Node
    {
        $files = [];

        for ($at = 0; $at < self::FILES; $at++) {
            $files[] = sprintf('/p/src/File%d.php', $at);
        }

        $ran = [];

        for ($at = 0; $at < $tests; $at++) {
            $places = [];

            for ($place = 0; $place < 8; $place++) {
                $places[] = ($at * 13 + $place * 29) % self::FILES;
            }

            $ran[] = [sprintf('Tests\\File%dTest::case%d', $at % self::FILES, $at), $places];
        }

        return Node::decode((string) json_encode(['outbreak' => 1, 'outline' => ['files' => $files, 'tests' => $ran]]));
    }
}
