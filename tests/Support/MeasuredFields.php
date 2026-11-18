<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function is_array;
use function json_decode;
use function json_encode;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\Results;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use RuntimeException;

/** A shard result's JSON with fields added to what it measured, as a job that measured its own time writes them. */
final readonly class MeasuredFields
{
    /** @param array<string, float|string> $fields */
    public static function added(string $json, array $fields): string
    {
        $data = json_decode($json, associative: true);

        if (! is_array($data) || ! is_array($data['measured'] ?? null)) {
            throw new RuntimeException('The result holds no measurement.');
        }

        $data['measured'] = [...$data['measured'], ...$fields];

        return (string) json_encode($data);
    }

    /** The results a plan's shards and jobs of the second wave left in a project. */
    public static function results(Plan $plan, string $project): Results
    {
        $results = Results::read($plan, Workspace::results(), Directory::at($project));

        return $results instanceof Results ? $results : throw new RuntimeException($results->why());
    }
}
