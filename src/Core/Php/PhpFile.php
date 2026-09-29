<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_filter;
use function array_map;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Hold\Holdings;
use PhpToken;

/**
 * What a PHP file declares, the names it mentions, whether loading it runs
 * anything, and the paths its `#[Holds]` declare held, read from its tokens.
 * Reading it runs none of it.
 */
final readonly class PhpFile
{
    /** How a name is spelt. */
    private const array NAMES = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

    private function __construct(
        private Names $declares,
        private Names $mentions,
        private bool $onlyDeclares,
        private Holdings $holdings,
    ) {
    }

    public static function read(Contents $contents): self
    {
        $tokens = array_filter(
            PhpToken::tokenize($contents->text()),
            static fn(PhpToken $token): bool => ! $token->isIgnorable() && ! $token->is(T_CLOSE_TAG),
        );
        $top = TopLevel::of($tokens);
        $scope = $top->scope();

        return new self(
            Names::of(...array_map($scope->declared(...), $top->declared())),
            self::mentioned($tokens, $scope),
            $top->onlyDeclares(),
            HoldsAttributes::in(TopLevel::spelt(...$tokens), $scope),
        );
    }

    /** Every class, interface, trait, enum and function the file declares at its top. */
    public function declares(): Names
    {
        return $this->declares;
    }

    /** Whether the file mentions any of these names. */
    public function mentions(Names $names): bool
    {
        return $this->mentions->meet($names);
    }

    /** Whether loading the file only declares, so that it acts on nothing that does not name it. */
    public function onlyDeclares(): bool
    {
        return $this->onlyDeclares;
    }

    /** The paths the file's `#[Holds]` declare held, each with the class or method that holds it. */
    public function holdings(): Holdings
    {
        return $this->holdings;
    }

    /** @param array<int, PhpToken> $tokens */
    private static function mentioned(array $tokens, Scope $scope): Names
    {
        $names = Names::of();

        foreach ($tokens as $token) {
            $names = $token->is(self::NAMES) ? $names->merge($scope->resolve($token->text)) : $names;
        }

        return $names;
    }
}
