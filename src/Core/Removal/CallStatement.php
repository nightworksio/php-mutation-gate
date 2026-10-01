<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Removal;

use NightWorksIO\MutationGate\Core\Php\Declarations;
use NightWorksIO\MutationGate\Core\Php\Declared;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Php\Names;
use NightWorksIO\MutationGate\Core\Php\OwnMember;
use NightWorksIO\MutationGate\Core\Php\Source;
use NightWorksIO\MutationGate\Core\Php\Tokens;
use NightWorksIO\MutationGate\Core\Php\Undeclared;

/**
 * A statement that is one call and nothing else, whose callee a name leads
 * to: `$this->m(…);`, `self::m(…);`, `static::m(…);`, `Name::m(…);` or
 * `f(…);`. Any other statement, a chain or a call on another object among
 * them, calls nothing a removal can suggest deleting (ADR-0025, decision 11).
 */
final readonly class CallStatement
{
    private function __construct(private Receiver $receiver, private string $class, private string $name)
    {
    }

    /** The one call a statement's significant tokens make; nothing where it is not one call and nothing else. */
    public static function read(Tokens $tokens): self|Nameless
    {
        $receiver = self::receiverOf($tokens);
        $named = $receiver === Receiver::Nothing ? 0 : 2;
        $name = $receiver === Receiver::Nothing ? Names::TOKENS : [T_STRING];
        $whole = $tokens->is($named, ...$name)
            && $tokens->is($named + 1, '(')
            && $tokens->closing($named + 1) === $tokens->count() - 2
            && $tokens->is($tokens->count() - 1, ';');
        $class = $receiver === Receiver::AClass ? $tokens->text(0) : '';

        return $whole ? new self($receiver, $class, $tokens->text($named)) : Nameless::code();
    }

    /**
     * The function or method the call leads to among the declarations, from
     * the source that makes it and a token of the statement there: a method
     * of the class around it, a method of the class a name resolves to
     * through the file's imports, or the function a name resolves to first.
     * A method a trait calls on itself leads nowhere, since a class that uses
     * the trait may override it. An unqualified function leads to the
     * namespace's own only: PHP calls the global one where the namespace's is
     * declared nowhere, which declarations of some files cannot show.
     */
    public function calleeIn(Declarations $declarations, Source $source, int $at): Declared|Undeclared
    {
        return match ($this->receiver) {
            Receiver::TheCase => $this->onTheCase($declarations, $source, $at),
            Receiver::AClass => $this->onAClass($declarations, $source),
            Receiver::Nothing => $declarations->function($source->scope()->resolve($this->name)->first()),
        };
    }

    private function onTheCase(Declarations $declarations, Source $source, int $at): Declared|Undeclared
    {
        $around = $source->classAround($at);
        $class = $around->name();

        return $class instanceof Nameless || $around->isTrait()
            ? Undeclared::callee()
            : $declarations->method($class, $this->name);
    }

    private function onAClass(Declarations $declarations, Source $source): Declared|Undeclared
    {
        foreach ($source->scope()->resolve($this->class)->all() as $class) {
            $found = $declarations->method($class, $this->name);

            if ($found instanceof Declared) {
                return $found;
            }
        }

        return Undeclared::callee();
    }

    /**
     * What the statement's first tokens call on: the class it is in, a class
     * it names, or nothing, which a parent's method is read as, since the
     * class it extends may be outside the project.
     */
    private static function receiverOf(Tokens $tokens): Receiver
    {
        return match (true) {
            OwnMember::isParent($tokens, 0) => Receiver::Nothing,
            OwnMember::calledAt($tokens, 1) => Receiver::TheCase,
            $tokens->is(0, ...Names::TOKENS) && $tokens->is(1, T_DOUBLE_COLON) => Receiver::AClass,
            default => Receiver::Nothing,
        };
    }
}
