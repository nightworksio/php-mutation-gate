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
 * (ADR-0013, decision 10): the program printed with every function's body
 * taken out, so that what is left is its classes, their constants,
 * properties, cases, attributes and modifiers, every function's signature,
 * and, from each body, the static variables and the classes and closures it
 * declares. A literal `__LINE__` among them is written as the line it
 * stands for. Two programs whose opcodes and declarations are the same are
 * one program.
 */
final readonly class Declarations
{
    private function __construct(private string $printed)
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

        $traverser = new NodeTraverser(new BodiesOut());

        return new self(new Standard()->prettyPrintFile($traverser->traverse($statements ?? [])));
    }

    /** Whether another program declares the same. */
    public function same(self $other): bool
    {
        return $this->printed === $other->printed;
    }
}
