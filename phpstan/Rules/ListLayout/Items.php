<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules\ListLayout;

use function array_any;
use function array_map;
use function array_values;
use function count;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * The arguments of a call or the parameters of a declaration, with the line the
 * list is anchored to and the indent it is measured from, as SonarCloud's
 * S1808 reads them: a call is anchored to the line its callee's name ends on,
 * and a declaration to the line of its name, or of `function` for a closure.
 */
final readonly class Items
{
    private const int INDENT = 4;

    /** @param list<Item> $items */
    private function __construct(
        private int $line,
        private int $indent,
        private array $items,
        private Source $source,
    ) {
    }

    /**
     * The list a node holds, or none where the node is not a call or a
     * declaration S1808 reads.
     *
     * @return list<self>
     */
    public static function of(Node $node, Source $source): array
    {
        return match (true) {
            $node instanceof FuncCall, $node instanceof MethodCall, $node instanceof NullsafeMethodCall, $node instanceof StaticCall => self::ofCall($node, $node->name, $source),
            $node instanceof New_ && ! $node->class instanceof Class_ => self::ofCall($node, $node->class, $source),
            $node instanceof Function_, $node instanceof ClassMethod => self::ofDeclaration($node->params, $node->name->getStartLine(), $node, $source),
            $node instanceof Closure => self::ofDeclaration($node->params, $source->lineOf($source->functionFrom($node->getStartFilePos())), $node, $source),
            default => [],
        };
    }

    /** Whether the list is too short to be laid out either way. */
    public function isShort(): bool
    {
        return count($this->items) < 2;
    }

    /**
     * Whether every item starts on the anchor line and the last ends there. An
     * array literal or a `function` closure may span lines, and the next item
     * then starts on the line it ends on.
     */
    public function isOnOneLine(): bool
    {
        $expected = $this->line;

        foreach ($this->items as $item) {
            if ($item->firstLine !== $expected) {
                return false;
            }

            $expected = $item->maySpanLines ? $item->lastLine : $expected;
        }

        return $this->last()->lastLine === $expected;
    }

    /**
     * Whether an item starts on the anchor line, or on a line an item before it
     * already starts on. A short array or a `function` closure owns every line
     * it spans.
     */
    public function isSplitWrongly(): bool
    {
        $expected = $this->line + 1;

        foreach ($this->items as $item) {
            if ($item->firstLine < $expected) {
                return true;
            }

            $expected = $item->keepsItsLines ? $item->lastLine + 1 : $expected + 1;
        }

        return false;
    }

    /** Whether an item starts anywhere but one indent past the anchor line's. */
    public function isMisaligned(): bool
    {
        return array_any($this->items, fn(Item $item): bool => $item->column !== $this->column());
    }

    /** Whether the closing parenthesis shares the last item's line, where that item does not end in one. */
    public function crowdsItsCloser(): bool
    {
        return ! $this->last()->endsWithCloser && $this->closerLine() === $this->last()->lastLine;
    }

    /** The column every item of a split list starts at. */
    public function column(): int
    {
        return $this->indent + self::INDENT;
    }

    public function anchorLine(): int
    {
        return $this->line;
    }

    public function firstLine(): int
    {
        return $this->items[0]->firstLine;
    }

    /** The line of the `)` that closes the list: the first after its last item. */
    public function closerLine(): int
    {
        return $this->source->lineOf($this->source->closerAfter($this->last()->end));
    }

    /**
     * A call's arguments, anchored to the line its callee ends on. A
     * first-class callable has none.
     *
     * @return list<self>
     */
    private static function ofCall(CallLike $call, Node $callee, Source $source): array
    {
        if ($call->isFirstClassCallable()) {
            return [];
        }

        $line = $callee->getEndLine();
        $items = array_map(static fn(Arg $argument): Item => Item::argument($argument, $source), array_values($call->getArgs()));

        return [new self($line, $source->indentOf($line), $items, $source)];
    }

    /**
     * @param array<Param> $params
     *
     * @return list<self>
     */
    private static function ofDeclaration(array $params, int $line, Node $declaration, Source $source): array
    {
        $indent = $source->indentOf($source->lineOf($source->functionFrom($declaration->getStartFilePos())));
        $items = array_map(static fn(Param $parameter): Item => Item::parameter($parameter, $source), array_values($params));

        return [new self($line, $indent, $items, $source)];
    }

    private function last(): Item
    {
        return $this->items[count($this->items) - 1];
    }
}
