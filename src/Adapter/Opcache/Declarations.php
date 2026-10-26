<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Opcache;

use NightWorksIO\MutationGate\Core\File\Contents;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * What a program declares, which its optimized opcodes do not show
 * (ADR-0013): the program printed with every function's body
 * taken out, so that what is left is its classes, their constants,
 * properties, cases, attributes and modifiers, every function's signature,
 * and, from each body, the static variables and the classes and closures it
 * declares. A literal `__LINE__` among them is written as the line it
 * stands for. Besides, the constant expressions the program writes, which
 * opcache folds into values its dump prints only in part (see Constants).
 * Two programs whose opcodes and declarations are the same are one
 * program, where the dump prints every value in full or both write the
 * same constant expressions.
 */
final readonly class Declarations
{
    /** @param list<string> $constants */
    private function __construct(private string $printed, private array $constants)
    {
    }

    /** A program's declarations, or that it does not parse, which proves nothing. */
    public static function of(Contents $program): self|Uncompiled
    {
        try {
            $statements = new ParserFactory()->createForNewestSupportedVersion()->parse($program->text());
        } catch (Error) {
            return Uncompiled::Failed;
        }

        $parsed = $statements ?? [];
        $printer = new Standard();
        $constants = new Constants($printer);
        new NodeTraverser($constants)->traverse($parsed);
        $traverser = new NodeTraverser(new BodiesOut());

        return new self($printer->prettyPrintFile($traverser->traverse($parsed)), $constants->printed());
    }

    /** Whether another program declares the same. */
    public function same(self $other): bool
    {
        return $this->printed === $other->printed;
    }

    /** Whether another program writes the same constant expressions, in the same order. */
    public function writesTheSameConstants(self $other): bool
    {
        return $this->constants === $other->constants;
    }
}
