<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamLine;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\Coverage\TestEnded;
use NightWorksIO\MutationGate\Core\Plan\ShardId;

use function sprintf;

/** What the streams of a project's shard 1 recorded: `MoneyTest::adds` running `src/Money.php` line 11, in half a second. */
final readonly class RecordedShard
{
    /** The lines written where the shard's streams record, in this project. */
    public static function in(string $project): void
    {
        $money = sprintf('%s/src/Money.php', $project);

        Scratch::write($project, sprintf('%s/recorded-1-0.jsonl', Workspace::recording(ShardId::of(1))->value()), sprintf(
            '%s%s',
            StreamLine::covered('MoneyTest::adds', [$money => [11]], new TestEnded(passed: true, troubled: false, own: false, seconds: 0.5)),
            StreamLine::finished([$money => [11, 12]]),
        ));
    }
}
