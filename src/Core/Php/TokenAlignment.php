<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_key_exists;
use function count;

/**
 * Which token of one text each token of another stands for, where the two
 * write the same code in two layouts, as a file and php-parser's print of it
 * do: the print drops a trailing comma, adds parentheses and respells a
 * number or a heredoc. The tokens the two share are the longest run of them
 * that both hold in order, which Myers' diff finds in time that grows with
 * how many tokens differ. A token of the second that the first does not
 * share stands for the token after the last shared one before it.
 */
final readonly class TokenAlignment
{
    /** How far along the first the diff starts: as if diagonal 1 reached the start, so the first step lands on 0. */
    private const array START = [1 => 0];

    /**
     * @param array<int, int> $shared where each token the second shares with the first stands in the first, by
     *                               its index in the second
     */
    private function __construct(private array $shared)
    {
    }

    /**
     * @param list<string> $first  each token's text, in order
     * @param list<string> $second each token's text, in order
     */
    public static function of(array $first, array $second): self
    {
        return new self(self::shared(self::trace($first, $second), count($first), count($second)));
    }

    /** Where the token at an index of the second stands in the first, or past the first's last where none follows. */
    public function inFirst(int $at): int
    {
        for ($before = $at; $before >= 0; $before--) {
            if (array_key_exists($before, $this->shared)) {
                return $before === $at ? $this->shared[$at] : $this->shared[$before] + 1;
            }
        }

        return 0;
    }

    /**
     * How far along the first each diagonal reached before each step of the
     * diff, up to the step that reaches the end of both.
     *
     * @param list<string> $first
     * @param list<string> $second
     *
     * @return list<array<int, int>>
     */
    private static function trace(array $first, array $second): array
    {
        $reached = self::START;
        $trace = [];
        $done = false;

        for ($step = 0; ! $done; $step++) {
            $trace[] = $reached;
            [$reached, $done] = self::step($reached, $step, $first, $second);
        }

        return $trace;
    }

    /**
     * How far along the first each diagonal reaches after one more step, and
     * whether one reaches the end of both.
     *
     * @param array<int, int> $reached
     * @param list<string>    $first
     * @param list<string>    $second
     *
     * @return array{array<int, int>, bool}
     */
    private static function step(array $reached, int $step, array $first, array $second): array
    {
        for ($diagonal = -$step; $diagonal <= $step; $diagonal += 2) {
            $start = self::fromAbove($reached, $step, $diagonal)
                ? $reached[$diagonal + 1]
                : $reached[$diagonal - 1] + 1;
            $along = self::slide($first, $second, $start, $diagonal);
            $reached[$diagonal] = $along;

            if ($along >= count($first) && $along - $diagonal >= count($second)) {
                return [$reached, true];
            }
        }

        return [$reached, false];
    }

    /**
     * Whether a diagonal's path at a step comes from the diagonal above, one
     * token further along the second, rather than from the one below.
     *
     * @param array<int, int> $reached
     */
    private static function fromAbove(array $reached, int $step, int $diagonal): bool
    {
        return $diagonal === -$step || ($diagonal !== $step && $reached[$diagonal - 1] < $reached[$diagonal + 1]);
    }

    /**
     * How far along the first a path slides down its diagonal while the
     * tokens of the two agree.
     *
     * @param list<string> $first
     * @param list<string> $second
     */
    private static function slide(array $first, array $second, int $along, int $diagonal): int
    {
        while (
            $along < count($first)
            && $along - $diagonal < count($second)
            && $first[$along] === $second[$along - $diagonal]
        ) {
            $along++;
        }

        return $along;
    }

    /**
     * The tokens the two share, read back from the end of both along the
     * path the trace found.
     *
     * @param list<array<int, int>> $trace
     *
     * @return array<int, int>
     */
    private static function shared(array $trace, int $first, int $second): array
    {
        $shared = [];

        for ($step = count($trace) - 1; $step >= 0; $step--) {
            $diagonal = $first - $second;
            $previous = self::fromAbove($trace[$step], $step, $diagonal) ? $diagonal + 1 : $diagonal - 1;
            $fromFirst = $trace[$step][$previous];
            $fromSecond = $fromFirst - $previous;

            while ($first > $fromFirst && $second > $fromSecond) {
                $first--;
                $second--;
                $shared[$second] = $first;
            }

            [$first, $second] = [$fromFirst, $fromSecond];
        }

        return $shared;
    }
}
