<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Push;

use function array_values;

use ArrayIterator;

use function count;

use Countable;

use function explode;

use IteratorAggregate;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;

use function preg_match;
use function sprintf;

use Traversable;

use function trim;

/**
 * The refs git is about to push, from what it hands the `pre-push` hook on
 * standard input: one line per ref, `<local ref> <local commit> <remote ref>
 * <remote commit>` (ADR-0010, decision 2).
 *
 * @implements IteratorAggregate<int, PushedRef>
 */
final readonly class Pushes implements Countable, IteratorAggregate
{
    /** A line git writes: four fields, each without a space, apart by one. */
    private const string LINE = '#^(\S+) (\S+) (\S+) (\S+)$#';

    private const string UNREAD = 'Git handed the pre-push hook a line it does not write: "%s".';

    private const string ELSEWHERE = <<<'SAID'
        %s is pushed at %s, and the working tree is at %s.
        The gate judges the working tree, so it cannot judge that push. Check out what you push, and push again.
        SAID;

    /** @param list<PushedRef> $refs */
    private function __construct(private array $refs)
    {
    }

    /** The refs git's lines name, or why a line cannot be read. */
    public static function read(string $text): self|CannotJudge
    {
        $refs = [];
        $lines = trim($text);

        foreach ($lines === '' ? [] : explode("\n", $lines) as $line) {
            if (preg_match(self::LINE, trim($line), $fields) !== 1) {
                return CannotJudge::because(sprintf(self::UNREAD, trim($line)));
            }

            $refs[] = PushedRef::of($fields[1], $fields[2], $fields[4]);
        }

        return new self($refs);
    }

    /**
     * The refs to judge, one for each base the change is read since, leaving
     * out deletions; or why a ref that sends a commit other than the one the
     * working tree is at cannot be judged.
     */
    public function judged(Revision $head, Revision $defaultBranch): self|CannotJudge
    {
        $judged = [];

        foreach ($this->refs as $ref) {
            if ($ref->deletes()) {
                continue;
            }

            if ($ref->local()->name() !== $head->name()) {
                return CannotJudge::because(
                    sprintf(self::ELSEWHERE, $ref->localRef(), $ref->local()->name(), $head->name()),
                );
            }

            $judged += [$ref->base($defaultBranch)->name() => $ref];
        }

        return new self(array_values($judged));
    }

    public function count(): int
    {
        return count($this->refs);
    }

    /** @return Traversable<int, PushedRef> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->refs);
    }
}
