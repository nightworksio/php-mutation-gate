<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Git;

use function array_key_exists;
use function array_map;
use function explode;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Commit;
use NightWorksIO\MutationGate\Core\Change\JudgedCommit;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Change\Tree;

use function sprintf;
use function str_starts_with;
use function trim;

/**
 * The objects git holds, as the gate reads them: the commit or tree a
 * revision names, each resolved once, what a commit was made of, and a
 * revision git can read for a commit a run judged, made again from its
 * parents where it is gone (ADR-0005, decision 2).
 */
final class Objects
{
    /** Why a revision cannot be read from. */
    private const string UNKNOWN = '%s is not a revision this repository has.';

    /** Why a revision cannot be read from a shallow clone, and what reads it. */
    private const string SHALLOW = <<<'SAID'
        %s is not a revision this repository has: the clone is shallow, so it holds only the newest commits.
        A clone of the whole history reads it, as `fetch-depth: 0` asks of `actions/checkout`.
        SAID;

    /** How a commit is asked for by name. */
    private const string AS_COMMIT = '%s^{commit}';

    /** How a tree is asked for by its id. */
    private const string AS_TREE = '%s^{tree}';

    /** The first git whose `merge-tree` writes the tree a merge gives, as a merge commit is made. */
    private const string MERGES_TREES = 'git version 2.38';

    private const string NOT_A_MERGE = '%s is gone, and it is no merge of two commits to make again.';

    private const string TOO_OLD = '%s cannot merge again the parents of %s, which is gone; git 2.38 can.';

    private const string OTHER_TREE = '%s is gone, and merging its parents again gives another tree than it held.';

    /** @var array<string, string|CannotTell> each revision read at, by its name: its commit, or why it has none */
    private array $commits = [];

    public function __construct(private readonly Command $git)
    {
    }

    /**
     * The commit a revision names, or the tree where it names one, resolved
     * the first time it is asked for, git asked for that object and for
     * nothing else. A name that begins with
     * `-` is refused, since git would read it as an option, and
     * `--end-of-options` keeps any other from being read as one.
     */
    public function of(Revision $revision): string|CannotTell
    {
        $name = $revision->name();

        if (! array_key_exists($name, $this->commits)) {
            $this->commits[$name] = $this->resolved($revision);
        }

        return $this->commits[$name];
    }

    /** A commit as a run judged it: the tree it holds and the commits it was made from. */
    public function judged(Revision $commit): JudgedCommit|CannotTell
    {
        $id = $this->of($commit);
        $printed = $id instanceof CannotTell ? $id : $this->git->run(['cat-file', 'commit', $id]);

        return $printed instanceof CannotTell ? $printed : CommitObject::read($id, $printed);
    }

    /** The commit a run judged where git has it, or else the tree merging its parents again gives back. */
    public function readable(JudgedCommit $judged): Revision|CannotTell
    {
        $commit = $this->of($judged->commit());

        return $commit instanceof CannotTell ? $this->rebuilt($judged) : Revision::ref($commit);
    }

    /** Whether the clone is shallow: it holds only the newest commits, and not the history before them. */
    public function isShallow(): bool|CannotTell
    {
        $shallow = $this->git->run(['rev-parse', '--is-shallow-repository']);

        return $shallow instanceof CannotTell ? $shallow : trim($shallow) === 'true';
    }

    /** The id of the object a revision names, asked of git; or why it names none git has. */
    private function resolved(Revision $revision): string|CannotTell
    {
        $name = $revision->name();
        $kind = $revision->isTree() ? self::AS_TREE : self::AS_COMMIT;
        $resolving = ['rev-parse', '--verify', '--quiet', '--end-of-options', sprintf($kind, $name)];
        $printed = str_starts_with($name, '-') ? $name : $this->git->run($resolving);
        $parsed = $printed instanceof CannotTell ? $printed : Commit::parse(trim($printed));

        return $parsed instanceof Commit
            ? $parsed->id()
            : CannotTell::because(sprintf($this->isShallow() === true ? self::SHALLOW : self::UNKNOWN, $name));
    }

    /**
     * The tree merging a gone commit's two parents again gives, where it is
     * the tree the commit held: git 2.38 or newer merges them as a merge
     * commit is made, with `merge-tree --write-tree`, and a merge that does
     * not come out clean, or a parent git does not have, as after a
     * force-push, gives none.
     */
    private function rebuilt(JudgedCommit $judged): Revision|CannotTell
    {
        $parents = $judged->parents();
        $name = $judged->commit()->name();

        if (! $parents->areOfAMerge()) {
            return CannotTell::because(sprintf(self::NOT_A_MERGE, $name));
        }

        [$first, $second] = array_map($this->of(...), [...$parents]);
        $version = $this->git->run(['version']);
        $merged = match (true) {
            $version instanceof CannotTell => $version,
            ! GitVersion::printed($version)->isAtLeast(GitVersion::printed(self::MERGES_TREES)) => CannotTell::because(
                sprintf(self::TOO_OLD, trim($version), $name),
            ),
            $first instanceof CannotTell => $first,
            $second instanceof CannotTell => $second,
            default => $this->git->run(['merge-tree', '--write-tree', '--no-messages', $first, $second]),
        };
        $tree = $merged instanceof CannotTell ? $merged : Tree::parse(trim(explode("\n", $merged)[0]));

        return match (true) {
            ! $tree instanceof Tree => $tree,
            $tree->id() !== $judged->tree()->id() => CannotTell::because(sprintf(self::OTHER_TREE, $name)),
            default => Revision::tree($tree),
        };
    }
}
