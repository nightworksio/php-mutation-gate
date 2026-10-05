<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Opcache;

use function max;
use function mb_strtolower;
use function min;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;

/**
 * Where a program reads the PHP it runs on in a way opcache decides as it
 * compiles: a call to `function_exists`, `extension_loaded` or `defined`, or
 * `PHP_VERSION_ID`, `PHP_OS`, `PHP_OS_FAMILY` or `PHP_INT_SIZE`. Opcache
 * answers each with what the compiling PHP has, which need not be what the
 * tests ran on, and then drops the branch it rules out, wherever in the
 * function the answer flows. A mutant that changes the function, method or
 * closure such a check is in, the constant, property or case of a class it
 * is in, or, where it is in code outside any, anything in the program, is
 * never proven equivalent (ADR-0013, decision 10).
 */
final readonly class RuntimeChecks
{
    /** @param list<array{int, int}> $spans each first and last byte opcache decides by a check */
    private function __construct(private string $original, private array $spans)
    {
    }

    /** Where a program reads the PHP it runs on; the whole of it where it does not parse. */
    public static function in(Contents $program): self
    {
        try {
            $statements = new ParserFactory()->createForNewestSupportedVersion()->parse($program->text()) ?? [];
        } catch (Error) {
            return new self($program->text(), [[0, Bytes::length($program->text())]]);
        }

        new NodeTraverser(new NodeConnectingVisitor())->traverse($statements);
        $spans = [];

        foreach (new NodeFinder()->findInstanceOf($statements, Expr::class) as $expression) {
            if (self::isCheck($expression)) {
                $spans[] = self::decidedBy($expression, Bytes::length($program->text()));
            }
        }

        return new self($program->text(), $spans);
    }

    /** Whether a mutant of the program changes anything such a check decides. */
    public function reached(Contents $mutant): bool
    {
        [$first, $last] = $this->changed($mutant->text());

        foreach ($this->spans as [$start, $end]) {
            if ($first <= $end && $last >= $start) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first and last byte of the program a mutant changes: where the two
     * first differ, to where they last differ, read from the end; one byte
     * where the mutant only adds.
     *
     * @return array{int, int}
     */
    private function changed(string $mutant): array
    {
        $shorter = min(Bytes::length($this->original), Bytes::length($mutant));
        $first = 0;

        while ($first < $shorter && $this->original[$first] === $mutant[$first]) {
            ++$first;
        }

        $fromEnd = 0;

        $originalLast = Bytes::length($this->original) - 1;
        $mutantLast = Bytes::length($mutant) - 1;

        while (
            $fromEnd < $shorter - $first
            && $this->original[$originalLast - $fromEnd] === $mutant[$mutantLast - $fromEnd]
        ) {
            ++$fromEnd;
        }

        return [$first, max($first, $originalLast - $fromEnd)];
    }

    private static function isCheck(Node $node): bool
    {
        $call = $node instanceof FuncCall && $node->name instanceof Name
            ? RuntimeCheck::tryFrom(mb_strtolower($node->name->toString()))
            : null;
        $constant = $node instanceof ConstFetch ? RuntimeCheck::tryFrom($node->name->toString()) : null;

        return $call instanceof RuntimeCheck || ($constant instanceof RuntimeCheck && ! $constant->isCall());
    }

    /**
     * The first and last byte of what opcache decides by a check, which a
     * value it folds can flow anywhere in: the function, method or closure
     * it is in; the constant, property or case of a class it is in; or, in
     * code outside any, the whole program.
     *
     * @return array{int, int}
     */
    private static function decidedBy(Node $check, int $length): array
    {
        $top = $check;
        $parent = $check->getAttribute('parent');

        while ($parent instanceof Node && ! $parent instanceof FunctionLike && ! $parent instanceof ClassLike) {
            $top = $parent;
            $parent = $parent->getAttribute('parent');
        }

        $decided = $parent instanceof FunctionLike ? $parent : $top;

        return $parent instanceof Node ? [$decided->getStartFilePos(), $decided->getEndFilePos()] : [0, $length];
    }
}
