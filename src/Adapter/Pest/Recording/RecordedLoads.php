<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function array_map;
use function explode;
use function file_get_contents;
use function getmypid;
use function is_file;
use function json_validate;

use NightWorksIO\MutationGate\Core\Coverage\LoadedPackages;
use NightWorksIO\MutationGate\Core\Coverage\ProcessLoads;
use NightWorksIO\MutationGate\Core\Coverage\TestLoad;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * What a line of `TimedTests` adds where a coverage run records what its tests loaded (ADR-0115): under its third
 * entry, the process that ran the test, the packages it first loaded and whether it started a process.
 *
 * @internal
 */
final readonly class RecordedLoads
{
    private const string RECORD = '2';

    private const string PROCESS = 'process';

    private const string PACKAGES = 'firstLoaded';

    private const string STARTED = 'startedProcesses';

    /** @return array{process: int|false, firstLoaded: list<string>, startedProcesses: bool} what a line records */
    public static function now(PackageWatch $watch): array
    {
        return [
            self::PROCESS => getmypid(),
            self::PACKAGES => $watch->newlyLoaded(),
            self::STARTED => $watch->startedAProcess(),
        ];
    }

    /** What the processes that wrote a file loaded, test by test; no record where no line holds one. */
    public static function in(string $file): LoadedPackages
    {
        if (! is_file($file)) {
            return LoadedPackages::unrecorded();
        }

        $processes = [];

        foreach (explode("\n", (string) file_get_contents($file)) as $line) {
            $record = json_validate($line) ? Node::decode($line) : Node::decode('{}');

            if ($record->field(self::RECORD)->isPresent()) {
                $process = Lenient::integer($record->field(self::RECORD)->field(self::PROCESS));
                $processes[$process][] = self::loadIn($record);
            }
        }

        return LoadedPackages::of(...array_map(
            static fn(array $tests): ProcessLoads => ProcessLoads::of(...$tests),
            $processes,
        ));
    }

    private static function loadIn(Node $line): TestLoad
    {
        $record = $line->field(self::RECORD);
        $packages = array_map(Lenient::text(...), Lenient::items($record->field(self::PACKAGES)));
        $load = TestLoad::of(Lenient::text($line->field('0')), ...$packages);

        return Lenient::boolean($record->field(self::STARTED), otherwise: true) ? $load->startingProcesses() : $load;
    }
}
