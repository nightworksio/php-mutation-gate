<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use Attribute;

use function mb_strtolower;

/**
 * The arguments of a file's attributes, each owned by the attribute's class,
 * or, for `#[Attribute]`, by the class-like it makes an attribute: a
 * reflection that makes the attribute reads them, and names that owner.
 */
final readonly class AttributeArguments
{
    /** What may stand between an attribute group and the class-like it stands on. */
    private const array PREAMBLE = [T_ATTRIBUTE, T_ABSTRACT, T_FINAL, T_READONLY];

    private function __construct(private Tokens $tokens, private Shape $shape, private Scope $scope)
    {
    }

    public static function in(Tokens $tokens, Shape $shape, Scope $scope): self
    {
        return new self($tokens, $shape, $scope);
    }

    /** The argument of the attribute whose arguments hold a token directly inside a group of attributes. */
    public function of(int $group, int $child): Symbol|Unnamed
    {
        $name = $this->tokens->text($this->tokens->is($child, '(') ? $child - 1 : $child);
        $class = $this->scope->className($name);

        return Symbol::attributeArgument(
            mb_strtolower($class) === mb_strtolower(Attribute::class) ? $this->standingOn($group) : $class,
            $name,
        );
    }

    /** The class-like an attribute group stands on, past other groups and modifiers; none where it stands on none. */
    private function standingOn(int $group): string|Nameless
    {
        $at = $this->tokens->closing($group) + 1;

        while ($this->tokens->is($at, ...self::PREAMBLE)) {
            $at = $this->tokens->is($at, T_ATTRIBUTE) ? $this->tokens->closing($at) + 1 : $at + 1;
        }

        $body = $this->tokens->is($at, ...Tokens::CLASS_LIKE) ? Shape::braceAfter($this->tokens, $at) : Tokens::NONE;

        return $this->shape->isClassBody($body) ? $this->shape->classOf($body)->name() : Nameless::code();
    }
}
