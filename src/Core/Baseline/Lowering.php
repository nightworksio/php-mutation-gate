<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Baseline;

use function array_filter;
use function array_values;
use function is_string;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\Failures;

use function sprintf;

/**
 * A floor goes down only on purpose. Against the base branch's baseline, a
 * floor that is lower has to carry `lowered`, from the base's floor and with
 * a reason; a tree leaves the file only when its path is no longer a tree,
 * and a security set only when its package's path is no longer a package
 * (ADR-0021, decision 17).
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

    private const string SECURITY_LEFT = <<<'SAID'
        The security set of %s left the baseline, and it is still a package.
        A security set leaves the baseline only when its package is gone.
        SAID;

    /** How a security set is named where its floor is. */
    private const string SECURITY_SET = 'the security set of %s';

    public static function against(Baseline $base, Baseline $head, Trees $trees): Failures
    {
        $failures = [];

        foreach ($base as $was) {
            $failures[] = self::failureOf(
                $was,
                $head->entryOf($was->path()),
                $was->path()->value(),
                self::isTree($was->path(), $trees) ? sprintf(self::LEFT, $was->path()->value()) : Kept::floor(),
            );
        }

        foreach ($base->security() as $was) {
            $failures[] = self::failureOf(
                $was,
                $head->securityOf($was->path()),
                sprintf(self::SECURITY_SET, $was->path()->value()),
                self::isPackage($was->path(), $trees)
                    ? sprintf(self::SECURITY_LEFT, $was->path()->value())
                    : Kept::floor(),
            );
        }

        return Failures::of(...array_values(array_filter(
            $failures,
            static fn(Failure|Kept $failure): bool => $failure instanceof Failure,
        )));
    }

    /** @param string|Kept $left why the entry may not leave the file, or that it may */
    private static function failureOf(Entry $was, Entry|Unrecorded $is, string $named, string|Kept $left): Failure|Kept
    {
        if (! $is instanceof Entry) {
            return is_string($left) ? Failure::that($left) : $left;
        }

        if ($is->floor()->hundredths() >= $was->floor()->hundredths()) {
            return Kept::floor();
        }

        return self::reasonFor($was, $is, $named);
    }

    private static function reasonFor(Entry $was, Entry $is, string $named): Failure|Kept
    {
        $lowered = $is->lowering();
        $from = BaselineFile::number($was->floor());

        return match (true) {
            ! $lowered instanceof Lowered || $lowered->reason() === '' => Failure::that(
                sprintf(self::UNSAID, $named, $from, BaselineFile::number($is->floor())),
            ),
            $lowered->floor()->hundredths() !== $was->floor()->hundredths() => Failure::that(
                sprintf(self::MISREAD, $named, $from, BaselineFile::number($lowered->floor())),
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

    private static function isPackage(Path $path, Trees $trees): bool
    {
        foreach ($trees as $tree) {
            if ($tree->package()->path()->equals($path)) {
                return true;
            }
        }

        return false;
    }
}
