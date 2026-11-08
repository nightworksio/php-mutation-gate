<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\KillerFile;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordEvent;
use NightWorksIO\MutationGate\Core\Mutant\TestShare;
use Pest\Mutate\Event\Facade;
use Pest\Mutate\MutationTest;
use Pest\Mutate\Support\MutationTestResult;

/**
 * What a shard's part of the test files makes of a mutant, where a run cuts
 * its test files across its shards (ADR-0117): one it leaves to the parts
 * that hold its covering tests is given a result, so it runs no own run, and
 * noted as not reached; one it judges by some of its covering tests alone is
 * noted so, and its own run, should a stream leave it, selects those alone.
 *
 * @internal
 */
final readonly class Parted
{
    /** The mutant, left to the parts that hold its covering tests. */
    public static function unreached(MutationTest $test): void
    {
        Noted::of($test, KillerFile::marked(RecordEvent::Unreached));
        $test->updateResult(MutationTestResult::Untested);
        Facade::instance()->emitter()->mutationEscaped($test);
    }

    /**
     * The mutant, judged by these covering tests, the part's, of the share
     * they are; nothing of a mutant the part holds every covering test of.
     *
     * @param list<string> $held
     */
    public static function judgedBy(MutationTest $test, TestShare $share, array $held): void
    {
        if ($share->isWhole()) {
            return;
        }

        Noted::of($test, KillerFile::marked($share->isOwned() ? RecordEvent::OwnedPart : RecordEvent::OtherPart));
        OwnCovering::narrow($test->mutation, $held);
    }
}
