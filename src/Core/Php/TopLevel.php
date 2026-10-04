<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_filter;
use function array_key_exists;
use function array_pop;
use function array_slice;
use function array_values;
use function count;
use function implode;

use PhpToken;

/**
 * The statements at the top of a PHP file, each as its significant tokens: a
 * declaration, an import, or code that runs when the file is loaded.
 */
final readonly class TopLevel
{
    /** What opens a variable inside a string, whose `}` ends no statement. */
    private const array INTERPOLATES = [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES];

    /** What ends a statement at the top. */
    private const array ENDS = [';', '}'];

    /** What a statement begins with before what it is: the end of an attribute, and a modifier. */
    private const array PREAMBLE = [']', T_ABSTRACT, T_FINAL, T_READONLY];

    /**
     * What a statement that runs nothing begins with: an empty one ends as it
     * begins, and a namespace's block ends with a `}` of its own.
     */
    private const array DECLARING = [
        ';',
        '}',
        T_NAMESPACE,
        T_USE,
        T_DECLARE,
        T_CONST,
        T_CLASS,
        T_INTERFACE,
        T_TRAIT,
        T_ENUM,
        T_FUNCTION,
    ];

    /** What a statement that declares a name begins with. */
    private const array NAMING = [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_FUNCTION];

    /** How a namespace is spelt. */
    private const array NAMESPACES = [T_STRING, T_NAME_QUALIFIED];

    /** @param list<non-empty-list<PhpToken>> $statements */
    private function __construct(private array $statements)
    {
    }

    /**
     * The statements of a file, each in a namespace's block read as one at
     * the top, as PHP runs it, past the namespace that opens the block.
     *
     * @param array<PhpToken> $tokens a file's significant tokens, in order
     */
    public static function of(array $tokens): self
    {
        $statements = [];
        $statement = [];
        $open = [];

        foreach ($tokens as $token) {
            $statement[] = $token;
            $block = self::opensNamespace($statement, count($open));
            [$open, $ends] = self::past($open, $token, $block);

            if ($block || $ends) {
                $statements[] = $statement;
                $statement = [];
            }
        }

        return new self($statement === [] ? $statements : [...$statements, $statement]);
    }

    /** Whether loading the file only declares, and runs nothing. */
    public function onlyDeclares(): bool
    {
        return $this->running() === [];
    }

    /**
     * The statements that run when the file is loaded, each as its
     * significant tokens: every one that declares nothing.
     *
     * @return list<non-empty-list<PhpToken>>
     */
    public function running(): array
    {
        return array_values(array_filter(
            $this->statements,
            /** @param non-empty-list<PhpToken> $statement */
            static fn(array $statement): bool => ! self::opens($statement, self::DECLARING),
        ));
    }

    /**
     * The name each class, interface, trait, enum and function declared at
     * the top is given, as written.
     *
     * @return list<string>
     */
    public function declared(): array
    {
        $names = [];

        foreach ($this->statements as $statement) {
            $names = self::opens($statement, self::NAMING)
                ? [...$names, $this->firstOf($statement, [T_STRING])]
                : $names;
        }

        return $names;
    }

    /** The namespace the file declares, and the names its `use` statements import. */
    public function scope(): Scope
    {
        $scope = Scope::global();

        foreach ($this->statements as $statement) {
            $scope = match (true) {
                $statement[0]->is(T_NAMESPACE) => $scope->inside($this->firstOf($statement, self::NAMESPACES)),
                $statement[0]->is(T_USE) => $scope->importing(...$statement),
                default => $scope,
            };
        }

        return $scope;
    }

    /** Tokens as text, joined by spaces. */
    public static function spelt(PhpToken ...$tokens): string
    {
        return implode(' ', $tokens);
    }

    /**
     * The brackets left open past a token, and whether it ends a statement at
     * the top: a `;`, or a `}` that closes the last bracket open, but for one
     * that closes a variable inside a string. The `{` that opens a namespace's
     * block is no bracket of a statement.
     *
     * @param list<PhpToken> $open
     *
     * @return array{list<PhpToken>, bool}
     */
    private static function past(array $open, PhpToken $token, bool $block): array
    {
        $closes = $token->is(Tokens::CLOSES) && $open !== [];
        $opener = $closes ? array_pop($open) : $token;
        $open = $block || ! $token->is(Tokens::OPENS) ? $open : [...$open, $token];

        return [$open, $open === [] && $token->is(self::ENDS) && ! $opener->is(self::INTERPOLATES)];
    }

    /**
     * Whether the statement read so far, at no depth, is a namespace whose
     * block its last token opens.
     *
     * @param non-empty-list<PhpToken> $statement
     */
    private static function opensNamespace(array $statement, int $depth): bool
    {
        return $depth === 0 && $statement[0]->is(T_NAMESPACE) && $statement[count($statement) - 1]->is('{');
    }

    /**
     * Whether a statement, past its attributes and modifiers, begins with one
     * of these.
     *
     * @param non-empty-list<PhpToken> $statement
     * @param list<int|string>         $kinds
     */
    private static function opens(array $statement, array $kinds): bool
    {
        $at = self::beginningOf($statement);

        return array_key_exists($at, $statement) && $statement[$at]->is($kinds);
    }

    /**
     * Where a statement begins past its attributes and modifiers, or its
     * length where it is nothing else.
     *
     * @param non-empty-list<PhpToken> $statement
     */
    private static function beginningOf(array $statement): int
    {
        $depth = 0;

        foreach ($statement as $at => $token) {
            $depth += match (true) {
                $token->is(T_ATTRIBUTE), $depth > 0 && $token->is('[') => 1,
                $token->is(']') => -1,
                default => 0,
            };

            if ($depth === 0 && ! $token->is(self::PREAMBLE)) {
                return $at;
            }
        }

        return count($statement);
    }

    /**
     * The text of the first token of one of these kinds past a statement's
     * beginning; empty where there is none.
     *
     * @param non-empty-list<PhpToken> $statement
     * @param list<int|string>         $kinds
     */
    private function firstOf(array $statement, array $kinds): string
    {
        foreach (array_slice($statement, self::beginningOf($statement)) as $token) {
            if ($token->is($kinds)) {
                return $token->text;
            }
        }

        return '';
    }
}
