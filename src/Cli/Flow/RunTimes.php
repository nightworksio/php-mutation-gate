<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\Phase;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Workspace as GateDirectory;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * What a run's jobs took, as the step before the verdict read the CI's API
 * for it (ADR-0017): `.mutation-gate/run-times.json`, `{"wallSeconds",
 * "runnerSeconds", "plan"?: {"at", "seconds"}}`. A file that is not there or cannot be read says nothing,
 * and the verdict estimates.
 *
 * @internal
 */
final readonly class RunTimes
{
    private function __construct(private RunTime $spent, private Phase|NotGiven $plan)
    {
    }

    public static function file(): Path
    {
        return Path::of(sprintf('%s/run-times.json', GateDirectory::root()->value()));
    }

    /** What the run's jobs took; nothing where these contents do not say. */
    public static function read(Contents|Missing|CannotJudge $contents): self|NotGiven
    {
        if (! $contents instanceof Contents) {
            return NotGiven::value();
        }

        try {
            $file = Node::decode($contents->text());

            return new self(
                RunTime::measured(
                    Seconds::of($file->field('wallSeconds')->number()),
                    Seconds::of($file->field('runnerSeconds')->number()),
                ),
                self::planIn($file->field('plan')),
            );
        } catch (NotInShape) {
            return NotGiven::value();
        }
    }

    public function spent(): RunTime
    {
        return $this->spent;
    }

    /** When the plan's job began and what it took; nothing where the API named no such job. */
    public function plan(): Phase|NotGiven
    {
        return $this->plan;
    }

    /** @throws NotInShape */
    private static function planIn(Node $plan): Phase|NotGiven
    {
        if (! $plan->isPresent()) {
            return NotGiven::value();
        }

        $at = Instant::parse($plan->field('at')->text());

        return $at instanceof Instant
            ? Phase::of($at, Seconds::of($plan->field('seconds')->number()))
            : throw NotInShape::at($plan->field('at')->at(), 'an instant');
    }
}
