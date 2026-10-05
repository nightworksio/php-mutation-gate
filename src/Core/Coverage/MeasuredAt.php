<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function is_string;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Commit;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

/**
 * Where a whole coverage map was measured (ADR-0020, decision 3): the commit
 * checked out, and whether the working tree held changes git had not
 * committed. A map records it as `commit` and `dirty`.
 */
final readonly class MeasuredAt
{
    public const string COMMIT = 'commit';

    public const string DIRTY = 'dirty';

    private function __construct(private Revision $commit, private bool $dirty)
    {
    }

    /** Measured at a commit, in a working tree that was clean or dirty. */
    public static function of(Revision $commit, bool $dirty): self
    {
        return new self($commit, $dirty);
    }

    /** Measured now, at the checkout's commit, as clean as git says; unplaced where git cannot tell either. */
    public static function now(Revision|CannotTell $head, bool|CannotTell $clean): self|Unplaced
    {
        return $head instanceof Revision && ! $clean instanceof CannotTell
            ? new self($head, dirty: ! $clean)
            : Unplaced::map();
    }

    /**
     * Where a map's bytes say it was measured, read within these limits; unplaced where they say nothing that can
     * be read.
     */
    public static function recordedIn(string $bytes, HandoffLimits $limits): self|Unplaced
    {
        $json = $limits->inflated($bytes);

        return self::readIn(Node::decode(is_string($json) ? $json : ''));
    }

    /** Where a decoded map says it was measured; unplaced where it says nothing that can be read. */
    public static function readIn(Node $map): self|Unplaced
    {
        try {
            $commit = Commit::parse($map->field(self::COMMIT)->text());
            $dirty = $map->field(self::DIRTY)->boolean();
        } catch (NotInShape) {
            return Unplaced::map();
        }

        return $commit instanceof Commit ? new self($commit->revision(), $dirty) : Unplaced::map();
    }

    public function commit(): Revision
    {
        return $this->commit;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    /** @return array{commit: string, dirty: bool} the fields a map records it in */
    public function written(): array
    {
        return [self::COMMIT => $this->commit->name(), self::DIRTY => $this->dirty];
    }
}
