<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardId;

/**
 * The CI job a plan's process runs in: the variables its CI set, which name
 * the job's shard, and the definitions that run the gate there.
 */
final readonly class CiJob
{
    /** The option of a CI plan that names the pipeline file that runs the gate. */
    public const string DEFINITION = 'definition';

    /** Why a CI plan's options name no pipeline that runs the gate. */
    public const string UNDEFINED = 'expected the pipeline that runs the gate, as a path';

    private function __construct(private Variables $variables, private Paths $definitions)
    {
    }

    /** The job with these variables, run from these definitions. */
    public static function of(Variables $variables, Paths $definitions): self
    {
        return new self($variables, $definitions);
    }

    /** The job with these variables, run from the pipeline a plan's options name as `definition`; or why none. */
    public static function definedIn(Options $options, Variables $variables): self|Invalid
    {
        $definition = $options->path(Key::of(self::DEFINITION));

        return match (true) {
            $definition instanceof Path => new self($variables, Paths::of($definition)),
            $definition instanceof Problem => Invalid::because($definition),
            default => Invalid::because(Problem::at(self::DEFINITION, self::UNDEFINED)),
        };
    }

    /** The variables the job's CI set. */
    public function variables(): Variables
    {
        return $this->variables;
    }

    /** The job's shard of a plan (see WhichShard). */
    public function shard(Plan $plan): ShardId|CannotJudge
    {
        return WhichShard::in($this->variables, $plan);
    }

    /** The definitions that run the gate in the job. */
    public function definitions(): Paths
    {
        return $this->definitions;
    }
}
