<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine;

use function array_key_exists;

use Closure;

use function get_object_vars;
use function in_array;
use function is_array;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Mutator\Mutator;
use PhpParser\Internal\TokenStream;
use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\PrettyPrinter\Standard;
use PhpParser\Token;

/**
 * php-parser's format-preserving printer for one file's changes, which
 * prints every node it is not asked to change as the code it was read from.
 * A change is printed alone and stands in the place of the code it
 * replaces, as printing the whole tree would put it: a replacement in its
 * node's place, or else its node's parent, copied to hold it, in the
 * parent's place. Where a part printed alone could join a word beside it,
 * which printing the whole tree parts with a space, the whole tree is
 * printed with the change in place.
 *
 * @internal the engine's own
 */
final class Printer extends Standard
{
    /** The width php-parser's printer gives a tab in every token stream it makes. */
    private const int TAB_WIDTH = 4;

    /**
     * @param list<Node>  $statements the code's statements, as read
     * @param list<Token> $tokens     the code's tokens
     */
    private function __construct(
        private readonly string $code,
        private readonly array $statements,
        private readonly array $tokens,
        private readonly TokenStream $stream,
    ) {
        parent::__construct();
        $this->initializeNodeListDiffer();
        $this->initializeLabelCharMap();
        $this->initializeFixupMap();
        $this->initializeRemovalMap();
        $this->initializeInsertionMap();
        $this->initializeListInsertionMap();
        $this->initializeEmptyListInsertionMap();
        $this->initializeModifierChangeMap();
    }

    /**
     * The printer of a code read into these statements and tokens.
     *
     * @param list<Node>  $statements
     * @param list<Token> $tokens
     */
    public static function of(string $code, array $statements, array $tokens): self
    {
        return new self($code, $statements, $tokens, new TokenStream($tokens, self::TAB_WIDTH));
    }

    /**
     * The edit of a replacement in a node's place in a copy of the code's
     * tree, the node's ancestors copied to hold it, so the tree itself is
     * left as it was.
     *
     * @param list<Node> $tree
     */
    public function edit(Node $node, Node|int $replacement, array $tree): Edit
    {
        $start = Line::of($node->getStartLine());
        $end = Line::of($node->getEndLine());
        $part = $this->part($node, $replacement, $start, $end);

        return $part instanceof Edit ? $part : Edit::of(
            $start,
            $end,
            $this->code,
            $this->printFormatPreserving(
                [...Replaced::in($node, $replacement, ...$tree)],
                $this->statements,
                $this->tokens,
            ),
        );
    }

    /** The edit of a change printed alone; none where it cannot be. */
    private function part(Node $node, Node|int $replacement, Line $start, Line $end): Edit|NotGiven
    {
        $printing = $replacement instanceof Node ? $this->placing($node, $replacement) : NotGiven::value();
        $alone = $printing instanceof Closure ? $this->printedAlone($node, $start, $end, $printing) : NotGiven::value();

        return $alone instanceof Edit ? $alone : $this->inParent($node, $replacement, $start, $end);
    }

    /** The edit of a node's parent, copied to hold a replacement, printed alone; none at the top of the tree. */
    private function inParent(Node $node, Node|int $replacement, Line $start, Line $end): Edit|NotGiven
    {
        $copy = Replaced::parentWith($node, $replacement);

        return $copy instanceof Node
            ? $this->printedAlone($copy, $start, $end, $this->printing($copy))
            : NotGiven::value();
    }

    /**
     * The parent's print of a replacement in its node's place; none where
     * the parent is printed otherwise, as an anonymous class's `new` is,
     * which is printed as one node of the class's parts and its arguments.
     *
     * @return (Closure(): string)|NotGiven
     */
    private function placing(Node $node, Node $replacement): Closure|NotGiven
    {
        $parent = $node->getAttribute(Mutator::PARENT);

        return $parent instanceof Node && ! $this->newOfAnonymousClass($parent)
            ? $this->placingIn($parent, $node, $replacement)
            : NotGiven::value();
    }

    private function newOfAnonymousClass(Node $node): bool
    {
        return $node instanceof New_ && $node->class instanceof Class_;
    }

    /**
     * The parent's print of a replacement in the place of one of its own
     * nodes: with the fixup it applies to that subnode and its class, or,
     * where the node is in a list, with the fixup it applies to the list's
     * items, where the replacement keeps the node's comments.
     *
     * @return (Closure(): string)|NotGiven
     */
    private function placingIn(Node $parent, Node $node, Node $replacement): Closure|NotGiven
    {
        foreach (get_object_vars($parent) as $name => $subnode) {
            $fixup = $this->fixup($parent, $name);

            if ($subnode === $node) {
                return $this->placed($replacement, $node, $fixup, $parent::class);
            }

            if (is_array($subnode) && in_array($node, $subnode, strict: true)) {
                return $this->listed($replacement, $node, $fixup);
            }
        }

        return NotGiven::value();
    }

    /**
     * The print of a list's items of a replacement in its node's place;
     * none where it drops the node's comments, which the list prints afresh.
     *
     * @return (Closure(): string)|NotGiven
     */
    private function listed(Node $replacement, Node $node, int|NotGiven $fixup): Closure|NotGiven
    {
        return $replacement->getComments() === $node->getComments()
            ? $this->placed($replacement, $node, $fixup, NotGiven::value())
            : NotGiven::value();
    }

    /**
     * The fixup the parent's print applies to a replacement in one of its
     * subnodes, which never knows an original of its own; none where it
     * applies none there.
     */
    private function fixup(Node $parent, int|string $name): int|NotGiven
    {
        $fixups = array_key_exists($parent::class, $this->fixupMap) ? $this->fixupMap[$parent::class] : [];

        return array_key_exists($name, $fixups) ? $fixups[$name] : NotGiven::value();
    }

    /**
     * A replacement's print in a node's place, with a fixup where its parent
     * applies one, for the parent's class where it is not in a list.
     *
     * @return Closure(): string
     */
    private function placed(Node $replacement, Node $node, int|NotGiven $fixup, string|NotGiven $parentClass): Closure
    {
        return $fixup instanceof NotGiven ? $this->printing($replacement) : fn(): string => $this->pFixup(
            $fixup,
            $replacement,
            $parentClass instanceof NotGiven ? null : $parentClass,
            $node->getStartTokenPos(),
            $node->getEndTokenPos(),
        );
    }

    /**
     * A node's print as its parent's print prints a subnode it keeps.
     *
     * @return Closure(): string
     */
    private function printing(Node $node): Closure
    {
        return fn(): string => $this->p($node, self::MAX_PRECEDENCE, self::MAX_PRECEDENCE, parentFormatPreserved: true);
    }

    /**
     * The edit of a print in the place of the bytes a node was read from,
     * printed in the state printing the whole tree prints it in; none where
     * it could join a word before or after it, which printing the whole tree
     * would part with a space, or where it is empty.
     *
     * @param Closure(): string $printing
     */
    private function printedAlone(Node $standing, Line $start, Line $end, Closure $printing): Edit|NotGiven
    {
        $from = $this->tokens[$standing->getStartTokenPos()]->pos;
        $last = $this->tokens[$standing->getEndTokenPos()];
        $to = $last->pos + Bytes::length($last->text);
        $this->origTokens = $this->stream;
        $this->preprocessNodes($this->statements);
        $this->setIndentLevel($this->stream->getIndentationBefore($standing->getStartTokenPos()));
        $printed = $printing();

        return $printed === '' || $this->joinsBefore($printed, $from) || $this->joinsAfter($printed, $to)
            ? NotGiven::value()
            : Edit::spliced($start, $end, $this->code, $from, $to - $from, $printed);
    }

    private function joinsBefore(string $printed, int $from): bool
    {
        return $this->labelCharMap[$this->code[$from - 1]] && $this->labelCharMap[$printed[0]];
    }

    private function joinsAfter(string $printed, int $to): bool
    {
        return $to < Bytes::length($this->code)
            && $this->labelCharMap[$this->code[$to]]
            && $this->labelCharMap[$printed[-1]];
    }
}
