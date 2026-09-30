<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules\ListLayout;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Param;

/**
 * One argument or parameter of a list: the lines it spans, the column it
 * starts at, the offset it ends at, and whether it is one of the shapes
 * SonarCloud lets span lines.
 */
final readonly class Item
{
    private function __construct(
        public int $firstLine,
        public int $lastLine,
        public int $column,
        public int $end,
        public bool $maySpanLines,
        public bool $keepsItsLines,
        public bool $endsWithCloser,
    ) {
    }

    /**
     * An argument, read by its value. A named argument starts at its name.
     */
    public static function argument(Arg $argument, Source $source): self
    {
        $value = $argument->value;

        return new self(
            $value->getStartLine(),
            $value->getEndLine(),
            $source->column($argument->getStartLine(), $argument->getStartFilePos()),
            $value->getEndFilePos(),
            maySpanLines: $value instanceof Array_ || $value instanceof Closure,
            keepsItsLines: self::isShortArray($value) || $value instanceof Closure,
            endsWithCloser: $source->isCloserAt($value->getEndFilePos()),
        );
    }

    public static function parameter(Param $parameter, Source $source): self
    {
        return new self(
            $parameter->getStartLine(),
            $parameter->getEndLine(),
            $source->column($parameter->getStartLine(), $parameter->getStartFilePos()),
            $parameter->getEndFilePos(),
            maySpanLines: false,
            keepsItsLines: false,
            endsWithCloser: $source->isCloserAt($parameter->getEndFilePos()),
        );
    }

    private static function isShortArray(Node $value): bool
    {
        return $value instanceof Array_ && $value->getAttribute('kind') === Array_::KIND_SHORT;
    }
}
