<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Baseline;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\Failures;

use function sprintf;

/**
 * A floor goes down only on purpose. Against the base branch's baseline, a
 * floor that is lower has to carry `lowered`, from the base's floor and with
 * a reason, and a tree leaves the file only when its path is no longer a
 * tree.
 */
final readonly class Lowering
{
    private const string UNSAID = <<<'SAID'
        The floor of %1$s went down from %2$s to %3$s with no reason. A floor goes down only on purpose:
        add "lowered": { "from": %2$s, "reason": "…" } to its entry in the baseline.
        SAID;

    private const string MISREAD = <<<'SAID'
        The floor of %1$s went down from %2$s, and its "lowered" says it came from %3$s.
        Write "from": %2$s, the floor the base branch holds.
        SAID;

    private const string LEFT = <<<'SAID'
        %s left the baseline, and it is still a tree. A tree leaves the baseline only when its path is gone.
        SAID;

    public static function against(Baseline $base, Baseline $head, Trees $trees): Failures
    {
        $failures = [];

        foreach ($base as $was) {
            $failure = self::failureOf($was, $head->entryOf($was->tree()), $trees);

            if ($failure instanceof Failure) {
                $failures[] = $failure;
            }
        }

        return Failures::of(...$failures);
    }

    private static function failureOf(Entry $was, Entry|Unrecorded $is, Trees $trees): Failure|Kept
    {
        if (! $is instanceof Entry) {
            return self::isTree($was->tree(), $trees)
                ? Failure::that(sprintf(self::LEFT, $was->tree()->value()))
                : Kept::floor();
        }

        if ($is->floor()->hundredths() >= $was->floor()->hundredths()) {
            return Kept::floor();
        }

        return self::reasonFor($was, $is);
    }

    private static function reasonFor(Entry $was, Entry $is): Failure|Kept
    {
        $lowered = $is->lowering();
        $from = BaselineFile::number($was->floor());

        return match (true) {
            ! $lowered instanceof Lowered || $lowered->reason() === '' => Failure::that(
                sprintf(self::UNSAID, $was->tree()->value(), $from, BaselineFile::number($is->floor())),
            ),
            $lowered->floor()->hundredths() !== $was->floor()->hundredths() => Failure::that(
                sprintf(self::MISREAD, $was->tree()->value(), $from, BaselineFile::number($lowered->floor())),
            ),
            default => Kept::floor(),
        };
    }

    private static function isTree(Path $path, Trees $trees): bool
    {
        foreach ($trees as $tree) {
            if ($tree->path()->equals($path)) {
                return true;
            }
        }

        return false;
    }
}
