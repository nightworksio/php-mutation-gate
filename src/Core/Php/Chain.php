<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_slice;
use function count;

use PhpToken;

/**
 * A statement read as one call of a function, and the methods called and
 * properties read on what it returns, in turn, as in `test('adds', fn () =>
 * …)->with([1, 2])->group('money');`. A statement in any other shape is no
 * chain.
 */
final readonly class Chain
{
    /** How a call spells a global function's name, whether written fully qualified or not. */
    public const array FUNCTION_NAMES = [T_STRING, T_NAME_FULLY_QUALIFIED];

    /** What ends the statement. */
    private const string END = ';';

    /** @param list<Link> $links the function's call first, then each method call or property read */
    private function __construct(private array $links)
    {
    }

    /** @param list<PhpToken> $statement a statement's significant tokens, in order */
    public static function of(array $statement): self
    {
        $read = Tokens::of($statement);
        $last = count($statement) - 1;
        $shaped = $last > 1
            && $read->is($last, self::END)
            && $read->is(0, ...self::FUNCTION_NAMES)
            && $read->is(1, '(');

        return new self($shaped ? self::linksOf($statement, $read, $last) : []);
    }

    /** Whether the statement is a chain. */
    public function isChain(): bool
    {
        return $this->links !== [];
    }

    /** The function's call; read only of a chain. */
    public function called(): Link
    {
        return $this->links[0];
    }

    /**
     * Each method called and property read on what the function returns, in turn.
     *
     * @return list<Link>
     */
    public function methods(): array
    {
        return array_slice($this->links, 1);
    }

    /**
     * Each call and property read of a statement that begins with a function's call, in turn; none where the
     * statement leaves the chain before its end.
     *
     * @param  list<PhpToken> $statement
     * @return list<Link>
     */
    private static function linksOf(array $statement, Tokens $read, int $last): array
    {
        $links = [];
        $at = 0;

        while ($at !== Tokens::NONE && $at < $last) {
            $called = $read->is($at + 1, '(');
            $links[] = Link::of($read->text($at), $called ? self::argumentsOf($statement, $read, $at + 1) : []);
            $at = self::nextFrom($read, $called ? $read->closing($at + 1) + 1 : $at + 1, $last);
        }

        return $at === $last ? $links : [];
    }

    /**
     * Where the next link's name stands after a link that ends at an index: the statement's end where it ends
     * there, and none where the statement leaves the chain.
     */
    private static function nextFrom(Tokens $read, int $at, int $last): int
    {
        return match (true) {
            $at === $last => $last,
            $read->is($at, T_OBJECT_OPERATOR) && $read->is($at + 1, T_STRING) => $at + 1,
            default => Tokens::NONE,
        };
    }

    /**
     * The arguments of the call whose `(` stands at an index, split at the commas directly inside it.
     *
     * @param  list<PhpToken> $statement
     * @return list<Argument>
     */
    private static function argumentsOf(array $statement, Tokens $read, int $opener): array
    {
        $arguments = [];
        $from = $opener + 1;

        foreach ([...$read->inside($opener, ','), $read->closing($opener)] as $comma) {
            $arguments = $comma > $from
                ? [...$arguments, Argument::of(array_slice($statement, $from, $comma - $from))]
                : $arguments;
            $from = $comma + 1;
        }

        return $arguments;
    }
}
