<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Php;

use function array_map;
use function array_reverse;
use function ltrim;

use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Migration\Migrations;
use NightWorksIO\MutationGate\Core\Migration\Remove;
use NightWorksIO\MutationGate\Core\Migration\Retired;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\NodeFinder;

use function rtrim;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function usort;

/**
 * What a removal takes out of a PHP config, cut from its text (ADR-0026,
 * decision 3): a method of `Gate` a step removes, a `with()` argument one
 * removes, and a `with()` every argument of which one does. Cutting the
 * text, rather than printing the chain again, keeps each comment that
 * stands beside what goes: what stands on its own line goes with that line,
 * and what shares its line goes alone, with its comma.
 */
final readonly class ChainCuts
{
    private const string NEWLINE = "\n";

    private const string BLANKS = " \t";

    private const string SPACE = " \t\r\n";

    private const string ARROW = '->';

    private const string COMMA = ',';

    private function __construct(private string $code)
    {
    }

    /**
     * The code without what a removal takes out.
     *
     * @param array<Node> $statements the code's statements, their names resolved
     */
    public static function made(string $code, array $statements, Migrations $migrations): string
    {
        $cutting = new self($code);
        $cuts = [];

        foreach (new NodeFinder()->findInstanceOf($statements, MethodCall::class) as $call) {
            $cuts = [...$cuts, ...$cutting->cutsOf($call, $migrations)];
        }

        usort($cuts, static fn(array $one, array $other): int => [$one[0], $other[1]] <=> [$other[0], $one[1]]);
        $outer = [];
        $reached = 0;

        foreach ($cuts as [$start, $end]) {
            if ($start >= $reached) {
                $outer[] = [$start, $end];
                $reached = $end;
            }
        }

        foreach (array_reverse($outer) as [$start, $end]) {
            $rest = Bytes::slice($code, $end, Bytes::length($code) - $end);
            $code = sprintf('%s%s', Bytes::slice($code, 0, $start), $rest);
        }

        return $code;
    }

    /**
     * What goes of one call along the chain: all of it where a removal
     * retires it or empties its `with()`, or else each argument one retires.
     *
     * @return list<array{int, int}>
     */
    private function cutsOf(MethodCall $call, Migrations $migrations): array
    {
        $removed = $this->removedArguments($call, $migrations);

        return match (true) {
            $this->removes(RetiredCall::of($call, $migrations)),
            $removed !== [] && $removed === $call->args => [$this->link($call)],
            default => array_map($this->argument(...), $removed),
        };
    }

    /**
     * The arguments of a `with()` that a removal retires.
     *
     * @return list<Arg>
     */
    private function removedArguments(MethodCall $call, Migrations $migrations): array
    {
        $removed = [];

        foreach (RetiredCall::isWith($call) ? $call->args : [] as $argument) {
            $value = $argument instanceof Arg ? $argument->value : null;
            $removed = $value instanceof StaticCall && $this->removes(RetiredCall::of($value, $migrations))
                ? [...$removed, $argument]
                : $removed;
        }

        return $removed;
    }

    private function removes(object $retired): bool
    {
        return $retired instanceof Retired && $retired->step() instanceof Remove;
    }

    /**
     * A link of the chain: from the end of the line before, where nothing
     * stands before its arrow on its own line, or else from its arrow.
     *
     * @return array{int, int}
     */
    private function link(MethodCall $call): array
    {
        $before = rtrim(Bytes::slice($this->code, 0, $call->name->getStartFilePos()), self::SPACE);
        $arrow = Bytes::length($before) - Bytes::length(self::ARROW);

        return [$this->lineStart($arrow), $call->getEndFilePos() + 1];
    }

    /**
     * An argument: its whole line, where it stands on a line of its own, or
     * else itself and the comma after it, or, where it is the last, the one
     * before it.
     *
     * @return array{int, int}
     */
    private function argument(Arg $argument): array
    {
        $start = $argument->getStartFilePos();
        $end = $argument->getEndFilePos() + 1;
        $line = $this->lineStart($start);

        if ($line < $start) {
            return [$line, $this->lineEnd($end)];
        }

        $rest = ltrim(Bytes::slice($this->code, $end, Bytes::length($this->code) - $end), self::BLANKS);

        if (str_starts_with($rest, self::COMMA)) {
            $tail = ltrim(Bytes::slice($rest, Bytes::length(self::COMMA), Bytes::length($rest)), self::BLANKS);

            return [$start, Bytes::length($this->code) - Bytes::length($tail)];
        }

        $before = rtrim(Bytes::slice($this->code, 0, $start), self::SPACE);

        return [Bytes::length($before) - Bytes::length(self::COMMA), $end];
    }

    /** Where the newline before this offset is, where only blanks stand between them; or the offset itself. */
    private function lineStart(int $offset): int
    {
        $lead = rtrim(Bytes::slice($this->code, 0, $offset), self::BLANKS);

        return str_ends_with($lead, self::NEWLINE) ? Bytes::length($lead) - 1 : $offset;
    }

    /** Past the end of the line this offset stands on, before its newline. */
    private function lineEnd(int $offset): int
    {
        $newline = Bytes::find($this->code, self::NEWLINE, $offset);

        return $newline === false ? Bytes::length($this->code) : $newline;
    }
}
