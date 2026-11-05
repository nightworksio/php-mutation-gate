<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Heat;

use function array_any;
use function array_values;

use ArrayIterator;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Php\Declarations;
use NightWorksIO\MutationGate\Core\Php\Enclosing;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Php\Shape;
use NightWorksIO\MutationGate\Core\Php\Tokens;

use function sprintf;

use Traversable;

/**
 * The named functions and methods of a file, each with the lines it runs
 * over (ADR-0084), so a mutant is scored with the function its line is in:
 * the narrowest that holds it. A closure has no name, so its lines are the
 * function's around it; a function without a body is not held.
 *
 * @internal
 *
 * @implements IteratorAggregate<int, FunctionSpan>
 */
final readonly class FunctionSpans implements IteratorAggregate
{
    /** @param list<FunctionSpan> $spans */
    private function __construct(private array $spans)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(FunctionSpan ...$spans): self
    {
        return new self(array_values($spans));
    }

    /** Every named function and method with a body a file declares. */
    public static function in(Contents $file): self
    {
        $tokens = Tokens::in($file);
        $spans = [];

        foreach ($tokens->indicesOf(T_FUNCTION) as $keyword) {
            $span = self::declaredAt($tokens, $keyword);
            $spans = $span instanceof FunctionSpan ? [...$spans, $span] : $spans;
        }

        return new self($spans);
    }

    /** These, only those that hold one of these lines. */
    public function holding(Line ...$lines): self
    {
        $held = [];

        foreach ($this->spans as $span) {
            $held = array_any($lines, $span->holds(...)) ? [...$held, $span] : $held;
        }

        return new self($held);
    }

    /** The name of the narrowest function that holds a line; nameless where none does. */
    public function at(Line $line): string|Nameless
    {
        $found = Nameless::code();
        $width = PHP_INT_MAX;

        foreach ($this->spans as $span) {
            $narrower = $span->holds($line) && $span->width() < $width;
            $found = $narrower ? $span->name() : $found;
            $width = $narrower ? $span->width() : $width;
        }

        return $found;
    }

    /** @return Traversable<int, FunctionSpan> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->spans);
    }

    private static function declaredAt(Tokens $tokens, int $keyword): FunctionSpan|Nameless
    {
        $named = $tokens->functionName($keyword);
        $body = $named === Tokens::NONE ? Tokens::NONE : Shape::braceAfter($tokens, $keyword);

        if ($body === Tokens::NONE) {
            return Nameless::code();
        }

        $class = Enclosing::classOf($tokens, $keyword);
        $name = $tokens->text($named);

        return FunctionSpan::of(
            $class instanceof Nameless ? $name : sprintf(Declarations::METHOD, $class, $name),
            Line::of($tokens->line($keyword)),
            Line::of($tokens->line($tokens->closing($body))),
        );
    }
}
