<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_all;
use function array_key_exists;
use function array_map;
use function array_slice;
use function count;
use function implode;

use PhpToken;

/**
 * The statements at the top of a PHP file, each as its significant tokens: a
 * declaration, an import, or code that runs when the file is loaded.
 */
final readonly class TopLevel
{
    /** What opens a block. */
    private const array OPENS = ['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES];

    /** What ends a statement at the top. */
    private const array ENDS = [';', '}'];

    /** What a statement begins with before what it is: the end of an attribute, and a modifier. */
    private const array PREAMBLE = [']', T_ABSTRACT, T_FINAL, T_READONLY];

    /** What a statement that runs nothing begins with: an empty one ends as it begins. */
    private const array DECLARING = [
        ';',
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

    /** @param array<PhpToken> $tokens a file's significant tokens, in order */
    public static function of(array $tokens): self
    {
        $statements = [];
        $statement = [];
        $depth = 0;

        foreach ($tokens as $token) {
            $statement[] = $token;
            $depth += match (true) {
                $token->is(self::OPENS) => 1,
                $token->is('}') => -1,
                default => 0,
            };

            if ($depth === 0 && $token->is(self::ENDS)) {
                $statements[] = $statement;
                $statement = [];
            }
        }

        return new self($statement === [] ? $statements : [...$statements, $statement]);
    }

    /** Whether loading the file only declares, and runs nothing. */
    public function onlyDeclares(): bool
    {
        return array_all(
            $this->statements,
            /** @param non-empty-list<PhpToken> $statement */
            static fn(array $statement): bool => self::opens($statement, self::DECLARING),
        );
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
        return implode(' ', array_map(static fn(PhpToken $token): string => $token->text, $tokens));
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
