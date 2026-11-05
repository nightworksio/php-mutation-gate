<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_map;

use NightWorksIO\MutationGate\Core\Heat\FileHeat;
use NightWorksIO\MutationGate\Core\Heat\Heat;
use NightWorksIO\MutationGate\Core\Heat\Heatmap;
use NightWorksIO\MutationGate\Core\Score\Score;

/**
 * The heatmap as the JSON report and `summary.json` write it (ADR-0084):
 * the report, every file lowest first, each with the functions that hold
 * its mutants; the summary, the lowest-scoring files only. A file or
 * function with nothing to mutate has no score, and one the base has no
 * score for no base.
 *
 * @internal
 *
 * @phpstan-type HeatEntry array{name: string, score?: float, base?: float, notKilled: int}
 * @phpstan-type FileEntry array{path: string, score?: float, base?: float, notKilled: int, functions: list<HeatEntry>}
 * @phpstan-type LowEntry array{path: string, score?: float, base?: float, notKilled: int}
 */
final readonly class HeatmapJson
{
    /** @return array{heatmap?: list<FileEntry>} the report's `heatmap`, where the run scored any file */
    public static function report(Heatmap $heatmap): array
    {
        $files = [];

        foreach ($heatmap as $file) {
            $files[] = [
                'path' => $file->path()->value(),
                ...self::scored($file->heat()),
                'functions' => array_map(self::function(...), $file->functions()),
            ];
        }

        return $files === [] ? [] : ['heatmap' => $files];
    }

    /** @return array{heatmap?: list<LowEntry>} the summary's `heatmap`, where it lists any file */
    public static function summary(Heatmap $heatmap): array
    {
        $files = array_map(
            static fn(FileHeat $file): array => ['path' => $file->path()->value(), ...self::scored($file->heat())],
            $heatmap->lowest(),
        );

        return $files === [] ? [] : ['heatmap' => $files];
    }

    /** @return HeatEntry */
    private static function function(Heat $function): array
    {
        return ['name' => $function->name(), ...self::scored($function)];
    }

    /** @return array{score?: float, base?: float, notKilled: int} */
    private static function scored(Heat $heat): array
    {
        $score = $heat->score();
        $base = $heat->base();

        return [
            ...$score instanceof Score ? ['score' => $score->percent()] : [],
            ...$base instanceof Score ? ['base' => $base->percent()] : [],
            'notKilled' => $heat->notKilled(),
        ];
    }
}
