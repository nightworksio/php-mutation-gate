<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_key_exists;
use function mb_strtolower;

use NightWorksIO\MutationGate\Core\File\Line;

use function sprintf;

/**
 * The named functions and methods some files declare, each with its body:
 * a method by its class's fully qualified name and its own, a function by
 * its fully qualified name, each in lower case, as PHP compares them. A
 * method of an anonymous class, and one without a body, is not held.
 */
final readonly class Declarations
{
    /** How a method's key joins its class's name and its own. */
    private const string METHOD = '%s::%s';

    /** @param array<string, Declared> $declared each function and method, the first of its key, by that key */
    private function __construct(private array $declared)
    {
    }

    public static function of(Source ...$sources): self
    {
        $declared = [];

        foreach ($sources as $source) {
            foreach ($source->tokens()->indicesOf(T_FUNCTION) as $at) {
                foreach (self::declaredAt($source, $at) as $key => $function) {
                    $declared += [$key => $function];
                }
            }
        }

        return new self($declared);
    }

    /** The method a class declares by a name. */
    public function method(string $class, string $name): Declared|Undeclared
    {
        return $this->at(sprintf(self::METHOD, mb_strtolower($class), mb_strtolower($name)));
    }

    /** The first of the functions a name may stand for, in the order PHP tries them, that is declared. */
    public function function(Names $candidates): Declared|Undeclared
    {
        foreach ($candidates->all() as $candidate) {
            $found = $this->at($candidate);

            if ($found instanceof Declared) {
                return $found;
            }
        }

        return Undeclared::callee();
    }

    private function at(string $key): Declared|Undeclared
    {
        return array_key_exists($key, $this->declared) ? $this->declared[$key] : Undeclared::callee();
    }

    /**
     * The named function or method with a body that a `function` keyword at
     * an index declares, by its key; none where it declares no such thing.
     *
     * @return array<string, Declared>
     */
    private static function declaredAt(Source $source, int $at): array
    {
        $tokens = $source->tokens();
        $named = $tokens->functionName($at);
        $body = $named === Tokens::NONE ? Tokens::NONE : $source->shape()->bodyOf($at);
        $key = $body === Tokens::NONE ? Nameless::code() : self::keyOf($source, $at, $tokens->text($named));

        return $key instanceof Nameless ? [] : [$key => Declared::in(
            $source->path(),
            $tokens->text($named),
            Line::of($tokens->line($body)),
            Line::of($tokens->line($tokens->closing($body))),
        )];
    }

    /** The key of what a `function` keyword at an index declares; nameless for an anonymous class's method. */
    private static function keyOf(Source $source, int $at, string $name): string|Nameless
    {
        $enclosing = $source->tokens()->enclosing($at);

        if (! $source->shape()->isClassBody($enclosing)) {
            return mb_strtolower($source->scope()->declared($name));
        }

        $class = $source->shape()->classOf($enclosing)->key();

        return $class instanceof Nameless ? $class : sprintf(self::METHOD, $class, mb_strtolower($name));
    }
}
