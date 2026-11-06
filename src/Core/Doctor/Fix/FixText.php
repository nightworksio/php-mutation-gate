<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Fix;

use function count;
use function implode;
use function rtrim;
use function sprintf;

/**
 * What `doctor --fix` shows before it writes anything (ADR-0083): the diff
 * of every file it would change, then each finding it cannot fix, with what
 * to do in its place, and the question it asks.
 *
 * @internal
 */
final readonly class FixText
{
    public const string NOTHING = 'doctor --fix has nothing to change.';

    public const string UNWRITTEN = 'Nothing was written: run doctor --fix --yes to apply these changes.';

    private const string CHANGES = 'doctor --fix would change:';

    private const string REFUSED = 'doctor --fix cannot fix:';

    private const string REFUSAL = '- %s: %s';

    private const string ASK = 'Apply %d %s to %d %s?';

    private const string WROTE = 'Wrote %d %s.';

    /**
     * The preview: every diff, then every refusal.
     *
     * @param list<Edit> $edits
     */
    public static function preview(Repairs $repairs, array $edits): string
    {
        $diffs = [];

        foreach ($edits as $edit) {
            $diffs[] = rtrim($edit->diff());
        }

        $refusals = [];

        foreach ($repairs as $repair) {
            $refusals = $repair->isRefused()
                ? [...$refusals, sprintf(self::REFUSAL, $repair->slug()->value, $repair->refusal())]
                : $refusals;
        }

        return implode("\n\n", [
            ...$diffs === [] ? [self::NOTHING] : [self::CHANGES, implode("\n", $diffs)],
            ...$refusals === [] ? [] : [implode("\n", [self::REFUSED, ...$refusals])],
        ]);
    }

    /**
     * The question: how many changes to how many files.
     *
     * @param list<Edit> $edits
     */
    public static function question(Repairs $repairs, array $edits): string
    {
        $changes = 0;

        foreach ($repairs as $repair) {
            $changes += $repair->changes() === [] ? 0 : 1;
        }

        $files = count($edits);

        return sprintf(
            self::ASK,
            $changes,
            $changes === 1 ? 'change' : 'changes',
            $files,
            $files === 1 ? 'file' : 'files',
        );
    }

    /** What it says once it wrote this many files. */
    public static function wrote(int $files): string
    {
        return sprintf(self::WROTE, $files, $files === 1 ? 'file' : 'files');
    }
}
