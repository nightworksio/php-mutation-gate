<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_fill_keys;
use function array_map;
use function file_put_contents;

use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Serialization\Serializer;

use function serialize;
use function sprintf;

/** Coverage maps written as `--coverage-php` writes them, in the installed php-code-coverage's serialization format. */
final readonly class CoverageMaps
{
    /**
     * @param non-empty-string                                               $file      where the map is written
     * @param array<non-empty-string, array<positive-int, list<int<0, max>>|null>> $lines each line's tests, by
     *                                                                          index; null for a line that is not
     *                                                                          executable
     * @param list<non-empty-string>                                         $tests     every test, by index
     * @param array<non-empty-string, float>                                 $durations each test's seconds
     */
    public static function write(string $file, string $basePath, array $lines, array $tests, array $durations): void
    {
        $data = new ProcessedCodeCoverageData();
        $data->setTestIds($tests);
        $data->setLineCoverage(array_map(
            static fn(array $byLine): array => array_map(
                static fn(?array $indexes): ?array => $indexes === null ? null : array_fill_keys($indexes, 1),
                $byLine,
            ),
            $lines,
        ));
        $coverage = [
            'buildInformation' => [
                'timestamp' => 'Wed Sep 30 0:00:00 UTC 2026',
                'runtime' => ['name' => 'PHP', 'version' => PHP_VERSION, 'vendorUrl' => 'https://www.php.net/'],
                'phpCodeCoverage' => [
                    'version' => '14.3.5',
                    'serializationFormat' => Serializer::SERIALIZATION_FORMAT,
                    'driverInformation' => ['name' => 'PCOV', 'version' => '1.0.12'],
                ],
            ],
            'basePath' => $basePath,
            'codeCoverage' => $data,
            'testResults' => array_map(
                static fn(float $time): array => ['size' => 'unknown', 'status' => 'success', 'time' => $time],
                $durations,
            ),
        ];

        file_put_contents($file, sprintf(
            "<?php // phpunit/php-code-coverage serialization format %d\n"
            . "return \\unserialize(<<<'END_OF_COVERAGE_SERIALIZATION'\n%s\nEND_OF_COVERAGE_SERIALIZATION\n);",
            Serializer::SERIALIZATION_FORMAT,
            serialize($coverage),
        ));
    }
}
