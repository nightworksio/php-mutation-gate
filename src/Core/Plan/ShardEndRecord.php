<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * How a shard's CI job ended, as `.mutation-gate/results/<id>.ended.json`
 * holds it where the shard left no result: `how`, one of JobEnding's names,
 * `job`, the job's address, where the CI gave one, and `after`, for a
 * cancelled job, the number of the shard whose failure the CI cancelled it
 * after, where one failed before it. The reusable workflow's verdict job
 * writes it. A file that cannot be read says nothing.
 *
 * @internal the shape of the file
 */
final readonly class ShardEndRecord
{
    public const string HOW = 'how';

    public const string JOB = 'job';

    public const string AFTER = 'after';

    /** How the job ended, as the file says; nothing where it says nothing that can be read. */
    public static function decode(string $json): ShardEnd|NotGiven
    {
        try {
            return self::read(Node::decode($json));
        } catch (NotInShape) {
            return NotGiven::value();
        }
    }

    /** @throws NotInShape */
    private static function read(Node $file): ShardEnd|NotGiven
    {
        $how = JobEnding::tryFrom($file->field(self::HOW)->text());
        $job = $file->field(self::JOB);
        $after = $file->field(self::AFTER);

        return $how instanceof JobEnding ? ShardEnd::of(
            $how,
            $job->isPresent() ? $job->text() : NotGiven::value(),
            $after->isPresent() && $after->integer() > 0 ? ShardId::of($after->integer()) : NotGiven::value(),
        ) : NotGiven::value();
    }
}
