<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Verdict\BaselineState;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

/**
 * The JSON report's `provenance`: the gate that judged the verdict, the
 * commit it judged, the runner and every package it drives, how long the run
 * took, and how the baseline file stands (ADR-0009). Each part a run could
 * not tell is left out; the baseline's state is always given.
 *
 * @internal
 *
 * @phpstan-type PackageEntry array{package: string, version: string, reference: string}
 * @phpstan-type ProvenanceEntry array{
 *     gate?: PackageEntry,
 *     commit?: string,
 *     runner?: array{name: string, packages: list<PackageEntry>},
 *     wallSeconds?: float,
 *     baseline: array{file?: string, state: string},
 * }
 */
final readonly class ProvenanceJson
{
    /** @return ProvenanceEntry */
    public static function of(Verdict $verdict): array
    {
        $provenance = $verdict->account()->provenance();
        $gate = $provenance->gate();
        $commit = $provenance->commit();
        $runner = $provenance->runner();
        $timings = $verdict->account()->timings();
        $baseline = $provenance->baseline();

        return [
            ...$gate instanceof Version ? ['gate' => self::package($gate)] : [],
            ...$commit instanceof Revision ? ['commit' => $commit->name()] : [],
            ...$runner instanceof Identity ? ['runner' => self::runner($runner)] : [],
            ...$timings instanceof RunTimings ? ['wallSeconds' => $timings->spent()->wall()->seconds()] : [],
            'baseline' => [
                ...$baseline instanceof Path ? ['file' => $baseline->value()] : [],
                'state' => BaselineState::of($verdict, $baseline instanceof Path ? $baseline : Path::root())->value,
            ],
        ];
    }

    /** @return array{name: string, packages: list<PackageEntry>} */
    private static function runner(Identity $runner): array
    {
        $packages = [];

        foreach ($runner->versions() as $version) {
            $packages[] = self::package($version);
        }

        return ['name' => $runner->runner(), 'packages' => $packages];
    }

    /** @return PackageEntry */
    private static function package(Version $version): array
    {
        return [
            'package' => $version->package(),
            'version' => $version->version(),
            'reference' => $version->reference(),
        ];
    }
}
