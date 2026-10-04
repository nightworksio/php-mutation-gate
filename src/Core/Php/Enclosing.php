<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function implode;
use function mb_lcfirst;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;

use function sprintf;

/**
 * The innermost named function or method a line is in, read from its file's
 * tokens: its name, its parameters' names, and the class it is a method of,
 * so a test can call it with its parameters' names as placeholders
 * (ADR-0015, decision 4). A closure has no name, so a line inside one is in
 * the function around it.
 */
final readonly class Enclosing
{
    /**
     * What may stand before the `function` keyword of a method with a body,
     * which no abstract method has.
     */
    private const array MODIFIERS = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL];

    /** What declares a class-like whose body holds a method with a body, which no interface's method has. */
    private const array CLASSES = [T_CLASS, T_TRAIT, T_ENUM];

    /** The method PHP calls to make an object, which a test calls with `new`. */
    private const string CONSTRUCTOR = '__construct';

    /** @param list<string> $parameters each parameter's name, with its `$` */
    private function __construct(
        private string $name,
        private array $parameters,
        private string|Nameless $class,
        private bool $static,
    ) {
    }

    /** The function or method around a line of a file; nothing where the line is in none. */
    public static function in(Contents $file, Line $line): self|Nameless
    {
        $tokens = Tokens::in($file);
        $found = Nameless::code();
        $first = 0;

        foreach ($tokens->indicesOf(T_FUNCTION) as $keyword) {
            $read = self::read($tokens, $keyword, $line);
            $inner = $read instanceof self && $tokens->line($keyword) >= $first;
            [$found, $first] = $inner ? [$read, $tokens->line($keyword)] : [$found, $first];
        }

        return $found;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * The call a test makes to it, its parameters' names as placeholders:
     * `fits($amount, $limit)`, `$cart->fits($amount)`, `Cart::of($items)`,
     * or `new Cart($items)` for a constructor.
     */
    public function call(): string
    {
        $arguments = implode(', ', $this->parameters);
        $class = $this->class;

        return match (true) {
            $class instanceof Nameless => sprintf('%s(%s)', $this->name, $arguments),
            $this->name === self::CONSTRUCTOR => sprintf('new %s(%s)', $class, $arguments),
            $this->static => sprintf('%s::%s(%s)', $class, $this->name, $arguments),
            default => sprintf('$%s->%s(%s)', mb_lcfirst($class), $this->name, $arguments),
        };
    }

    /** The named function a `function` keyword declares, where its body holds the line; nothing otherwise. */
    private static function read(Tokens $tokens, int $keyword, Line $line): self|Nameless
    {
        $named = $tokens->functionName($keyword);
        $opener = $named + 1;

        if ($named === Tokens::NONE) {
            return Nameless::code();
        }

        $body = self::after($tokens, $keyword, $tokens->closing($opener));
        $holds = $tokens->is($body, '{')
            && $tokens->line($keyword) <= $line->number()
            && $line->number() <= $tokens->line($tokens->closing($body));

        return $holds
            ? new self(
                $tokens->text($named),
                self::parameters($tokens, $opener),
                self::classOf($tokens, $keyword),
                static: self::isStatic($tokens, $keyword),
            )
            : Nameless::code();
    }

    /** @return list<string> the names of the parameters the list that opens at an index declares */
    private static function parameters(Tokens $tokens, int $opener): array
    {
        $names = [];

        foreach ($tokens->inside($opener, T_VARIABLE) as $variable) {
            $names[] = $tokens->text($variable);
        }

        return $names;
    }

    /** The short name of the class a method's body stands inside; nothing for a function, or an anonymous class. */
    private static function classOf(Tokens $tokens, int $keyword): string|Nameless
    {
        $body = $tokens->enclosing($keyword);
        $class = Nameless::code();

        foreach ($tokens->is($body, '{') ? $tokens->indicesOf(...self::CLASSES) : [] as $declaring) {
            $opens = self::after($tokens, $declaring, $declaring) === $body && $tokens->is($declaring + 1, T_STRING);
            $class = $opens ? $tokens->text($declaring + 1) : $class;
        }

        return $class;
    }

    /**
     * Where the body of what a keyword declares opens: the first `{` or `;`
     * at the keyword's depth past an index, such as a function's parameters
     * and return type, or a class's name and parents.
     */
    private static function after(Tokens $tokens, int $keyword, int $past): int
    {
        $found = Tokens::NONE;

        foreach ($tokens->inside($tokens->enclosing($keyword), '{', ';') as $at) {
            $found = $found === Tokens::NONE && $at > $past ? $at : $found;
        }

        return $found;
    }

    /** Whether the modifiers before a `function` keyword make it static. */
    private static function isStatic(Tokens $tokens, int $keyword): bool
    {
        $static = false;

        for ($each = $keyword - 1; $each >= 0 && $tokens->is($each, ...self::MODIFIERS); $each--) {
            $static = $static || $tokens->is($each, T_STATIC);
        }

        return $static;
    }
}
