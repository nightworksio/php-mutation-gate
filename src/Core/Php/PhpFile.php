<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_keys;
use function array_map;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Hold\Holdings;
use NightWorksIO\MutationGate\Core\Hold\HoldsAttributes;
use PhpToken;

use function strval;

/**
 * What a PHP file declares, the names it mentions, whether loading it runs
 * anything, and the paths its `#[Holds]` declare held, read from its tokens.
 * Reading it runs none of it.
 */
final readonly class PhpFile
{
    private function __construct(
        private Names $declares,
        private Names $mentions,
        private bool $onlyDeclares,
        private HoldsAttributes $holds,
    ) {
    }

    public static function read(Contents $contents): self
    {
        $tokens = [];

        foreach (PhpToken::tokenize($contents->text()) as $token) {
            if (! $token->isIgnorable() && ! $token->is(T_CLOSE_TAG)) {
                $tokens[] = $token;
            }
        }

        $top = TopLevel::of($tokens);
        $scope = $top->scope();

        return new self(
            Names::of(...array_map($scope->declared(...), $top->declared())),
            self::mentionedIn($tokens, $scope),
            $top->onlyDeclares(),
            HoldsReader::mayHold($contents) ? HoldsReader::in(Tokens::of($tokens), $scope) : HoldsAttributes::none(),
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

    /** Every name the file mentions. */
    public function mentioned(): Names
    {
        return $this->mentions;
    }

    /** Whether loading the file only declares, so that it acts on nothing that does not name it. */
    public function onlyDeclares(): bool
    {
        return $this->onlyDeclares;
    }

    /** The paths the file's `#[Holds]` declare held, each with the class or method that holds it. */
    public function holdings(): Holdings
    {
        return $this->holds->holdings();
    }

    /** Every `#[Holds]` the file writes, on closures and functions as well as on classes and methods. */
    public function holds(): HoldsAttributes
    {
        return $this->holds;
    }

    /**
     * Every name the tokens spell, each spelling resolved once.
     *
     * @param list<PhpToken> $tokens
     */
    private static function mentionedIn(array $tokens, Scope $scope): Names
    {
        $spelt = [];

        foreach ($tokens as $token) {
            if ($token->is(Names::TOKENS)) {
                $spelt[$token->text] = true;
            }
        }

        $names = [];

        foreach (array_keys($spelt) as $name) {
            $names[] = $scope->resolve(strval($name));
        }

        return Names::of()->merge(...$names);
    }
}
