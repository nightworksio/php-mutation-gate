<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Commit;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

/**
 * A scope's last run as the ledger file holds it, under `lastRun`: `commit`,
 * the full id of the commit its verdict judged, `check`, the check-run it
 * reported under, and `kind`, the kind of run it was ({@see RunProfileRecord}).
 * A record that is not so shaped reads as no last run, and the rest of the
 * ledger stands.
 *
 * @internal the shape of the ledger file
 *
 * @phpstan-import-type Written from RunProfileRecord as WrittenKind
 *
 * @phpstan-type Written array{commit: string, check: string, kind: WrittenKind}
 */
final readonly class LastRunRecord
{
    public const string SECTION = 'lastRun';

    private const string COMMIT = 'commit';

    private const string CHECK = 'check';

    private const string KIND = 'kind';

    private const string NONE = 'The ledger holds no last run it can read.';

    /** @return Written */
    public static function of(LastRun $lastRun): array
    {
        return [
            self::COMMIT => $lastRun->commit()->name(),
            self::CHECK => $lastRun->check(),
            self::KIND => RunProfileRecord::of($lastRun->profile()),
        ];
    }

    /** The last run a ledger file's section holds, or none where it holds none it can read. */
    public static function read(Node $section): LastRun|CannotTell
    {
        try {
            $commit = Commit::parse($section->field(self::COMMIT)->text());
            $kind = $section->field(self::KIND);

            return LastRun::of(
                $commit instanceof Commit ? $commit->revision() : throw NotInShape::at($section->at(), 'a commit'),
                $section->field(self::CHECK)->text(),
                $kind->isPresent() ? RunProfileRecord::read($kind) : throw NotInShape::at($kind->at(), 'a kind of run'),
            );
        } catch (NotInShape) {
            return CannotTell::because(self::NONE);
        }
    }
}
