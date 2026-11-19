<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_diff;
use function array_keys;
use function array_map;
use function array_values;

use NightWorksIO\MutationGate\Core\Cost\Route;

/**
 * The JSON Schema of the JSON report's `costReport`, where a run's minutes
 * went (ADR-0230), as {@see AccountJson} writes it.
 *
 * @internal
 *
 * @phpstan-import-type Leaf from ReportSchema
 * @phpstan-import-type Flat from ReportSchema
 * @phpstan-import-type Grouped from ReportSchema
 */
final readonly class CostReportSchema
{
    /** @return Grouped */
    public static function report(): array
    {
        $route = ['enum' => array_map(static fn(Route $route): string => $route->value, Route::cases())];

        return self::object([
            'forkFloorSeconds' => ReportSchema::SECONDS,
            'routes' => self::rows(self::flat([
                'route' => $route,
                'mutants' => ReportSchema::WHOLE,
                'seconds' => ReportSchema::SECONDS,
                'coreSeconds' => ReportSchema::SECONDS,
                'coreSecondsPerMutant' => ReportSchema::SECONDS,
            ], ['coreSecondsPerMutant'])),
            'outsideRoutesSeconds' => ReportSchema::SECONDS,
            'mutants' => self::rows(self::flat([
                'id' => ReportSchema::ID_SPELLING,
                'file' => ReportSchema::TEXT,
                'line' => ReportSchema::LINE,
                'mutator' => ReportSchema::TEXT,
                'seconds' => ReportSchema::SECONDS,
                'route' => $route,
            ], ['route'])),
            'tests' => self::rows(self::flat([
                'test' => ReportSchema::TEXT,
                'ownSeconds' => ReportSchema::SECONDS,
                'mutants' => ReportSchema::WHOLE,
                'atMostSeconds' => ReportSchema::SECONDS,
            ], [])),
            'wallSeconds' => ReportSchema::SECONDS,
            'runnerSeconds' => ReportSchema::SECONDS,
            'jobs' => ReportSchema::WHOLE,
            'rounds' => ReportSchema::WHOLE,
            'unjudged' => ReportSchema::WHOLE,
            'coreSeconds' => ReportSchema::SECONDS,
            'suiteSeconds' => ReportSchema::SECONDS,
            'suiteMultiple' => ReportSchema::SECONDS,
        ], ['suiteMultiple']);
    }

    /**
     * @param  array<string, Leaf> $properties
     * @param  list<string>        $optional
     * @return Grouped
     */
    private static function object(array $properties, array $optional): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => self::required(array_keys($properties), $optional),
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, Leaf> $properties
     * @param  list<string>        $optional
     * @return Flat
     */
    private static function flat(array $properties, array $optional): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => self::required(array_keys($properties), $optional),
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  Flat                              $row
     * @return array{type: string, items: Flat}
     */
    private static function rows(array $row): array
    {
        return ['type' => 'array', 'items' => $row];
    }

    /**
     * @param  list<string> $names
     * @param  list<string> $optional
     * @return list<string>
     */
    private static function required(array $names, array $optional): array
    {
        return array_values(array_diff($names, $optional));
    }
}
