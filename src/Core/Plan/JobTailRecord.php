<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function is_int;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Proof\RunProfileRecord;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * How a plan's jobs stop, as its briefing holds it (ADR-0303): `tailLimit`,
 * the longest a job may run in seconds, `tailPool`, how many jobs run at
 * once, and `ownTail`, `true` where the jobs run their own tail. A file
 * without the limit and the pool stops its jobs at their target.
 *
 * @internal the shape of the plan file
 */
final readonly class JobTailRecord
{
    /** Where the jobs run their own tail, in a briefing and in a share of the second wave. */
    public const string OWN = 'ownTail';

    private const string LIMIT = 'tailLimit';

    private const string POOL = 'tailPool';

    /** @return array{tailLimit?: float, tailPool?: int, ownTail?: true} */
    public static function of(JobTail $tail): array
    {
        $limit = $tail->limit();
        $pool = $tail->pool();

        return [
            ...$limit instanceof Seconds ? [self::LIMIT => $limit->seconds()] : [],
            ...is_int($pool) ? [self::POOL => $pool] : [],
            ...$tail->runs() ? [self::OWN => true] : [],
        ];
    }

    /**
     * How a briefing's file says the plan's jobs stop.
     *
     * @throws NotInShape
     */
    public static function read(Node $file): JobTail
    {
        $limit = $file->field(self::LIMIT);
        $pool = $file->field(self::POOL);
        $tail = $limit->isPresent() && $pool->isPresent()
            ? JobTail::of(Seconds::of($limit->number()), $pool->integer())
            : JobTail::unknown();

        return RunProfileRecord::isTrue($file->field(self::OWN)) ? $tail->running() : $tail;
    }
}
