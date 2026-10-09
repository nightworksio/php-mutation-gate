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
 * The refs git is about to push, from the lines it hands the `pre-push` hook:
 * one line per ref, `<local ref> <local commit> <remote ref> <remote commit>`,
 * each commit named by its full object name in hex (ADR-0010 decision 2,
 * ADR-0024 decision 13). The lines reach the gate on standard input, through
 * `--stdin`, or rebuilt from what the pre-commit framework sets.
 *
 * @implements IteratorAggregate<int, PushedRef>
 */
final readonly class Pushes implements Countable, IteratorAggregate
{
    /** A line git writes: a ref, a commit, a ref and a commit, apart by one space; a SHA-1 or SHA-256 name each. */
    private const string LINE = '#^(\S+) ([0-9a-f]{40}(?:[0-9a-f]{24})?) (\S+) ([0-9a-f]{40}(?:[0-9a-f]{24})?)$#';

    private const string UNREAD = <<<'SAID'
        The pre-push hook was handed a line that is not a local ref, its commit, a remote ref and its commit: "%s".
        SAID;

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
