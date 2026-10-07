<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_map;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Commit;
use NightWorksIO\MutationGate\Core\Change\JudgedCommit;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Change\Tree;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

/**
 * A scope's last run as the ledger file holds it, under `lastRun`: `commit`,
 * the full id of the commit its verdict judged, `tree`, the full id of the
 * tree it held, `parents`, the full id of each commit it was made from, in
 * its order, `check`, the check-run it reported under, and `kind`, the kind of run it was ({@see RunProfileRecord}).
 * A record that is not so shaped reads as no last run, and the rest of the
 * ledger stands.
 *
 * @internal the shape of the ledger file
 *
 * @phpstan-import-type Written from RunProfileRecord as WrittenKind
 *
 * @phpstan-type Written array{commit: string, tree: string, parents: list<string>, check: string, kind: WrittenKind}
 */
final readonly class LastRunRecord
{
    public const string SECTION = 'lastRun';

    private const string COMMIT = 'commit';

    private const string TREE = 'tree';

    private const string PARENTS = 'parents';

    private const string CHECK = 'check';

    private const string KIND = 'kind';

    private const string NONE = 'The ledger holds no last run it can read.';

    /** @return Written */
    public static function of(LastRun $lastRun): array
    {
        $judged = $lastRun->judged();

        return [
            self::COMMIT => $judged->commit()->name(),
            self::TREE => $judged->tree()->id(),
            self::PARENTS => array_map(static fn(Revision $parent): string => $parent->name(), [...$judged->parents()]),
            self::CHECK => $lastRun->check(),
            self::KIND => RunProfileRecord::of($lastRun->profile()),
        ];
    }

    /** The last run a ledger file's section holds, or none where it holds none it can read. */
    public static function read(Node $section): LastRun|CannotTell
    {
        try {
            $kind = $section->field(self::KIND);

            return LastRun::of(
                self::judgedIn($section),
                $section->field(self::CHECK)->text(),
                $kind->isPresent() ? RunProfileRecord::read($kind) : throw NotInShape::at($kind->at(), 'a kind of run'),
            );
        } catch (NotInShape) {
            return CannotTell::because(self::NONE);
        }
    }

    /**
     * The commit a section names, with its tree and parents, each by its full id.
     *
     * @throws NotInShape
     */
    private static function judgedIn(Node $section): JudgedCommit
    {
        $commit = self::commitIn($section->field(self::COMMIT));
        $tree = Tree::parse($section->field(self::TREE)->text());
        $parents = array_map(self::commitIn(...), $section->field(self::PARENTS)->items());

        return $tree instanceof Tree
            ? JudgedCommit::of($commit, $tree, ...$parents)
            : throw NotInShape::at($section->field(self::TREE)->at(), 'a tree');
    }

    /**
     * A commit a field names by its full id.
     *
     * @throws NotInShape
     */
    private static function commitIn(Node $field): Revision
    {
        $commit = Commit::parse($field->text());

        return $commit instanceof Commit ? $commit->revision() : throw NotInShape::at($field->at(), 'a commit');
    }
}
