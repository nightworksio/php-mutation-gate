<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_keys;
use function array_map;
use function array_push;
use function ltrim;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Hold\Holdings;
use NightWorksIO\MutationGate\Core\Hold\HoldsAttributes;
use PhpToken;

use function preg_match_all;
use function str_replace;
use function strval;

/**
 * What a PHP file declares, the names it mentions, whether loading it runs
 * anything, and the paths its `#[Holds]` declare held, read from its tokens.
 * Reading it runs none of it.
 */
final readonly class PhpFile
{
    /** A fully qualified name as a string spells it, once its doubled backslashes are single. */
    private const string QUALIFIED = '/\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+/';

    private function __construct(
        private Names $declares,
        private Names $mentions,
        private Names $quoted,
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
            self::quotedIn($tokens),
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

    /**
     * Every fully qualified name the file spells inside a quoted string, as
     * a class-string does: `'App\\Fake'` names App\Fake.
     */
    public function quoted(): Names
    {
        return $this->quoted;
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
     * Every fully qualified name the quoted strings spell: two or more
     * segments joined by backslashes, single or doubled, without a leading one.
     *
     * @param list<PhpToken> $tokens
     */
    private static function quotedIn(array $tokens): Names
    {
        $names = [];

        foreach ($tokens as $token) {
            if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
                preg_match_all(self::QUALIFIED, str_replace('\\\\', '\\', $token->text), $found);
                array_push($names, ...array_map(static fn(string $name): string => ltrim($name, '\\'), $found[0]));
            }
        }

        return Names::of(...$names);
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
