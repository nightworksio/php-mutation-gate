<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_push;
use function count;
use function explode;
use function ltrim;
use function mb_strtolower;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Hold\Holdings;
use NightWorksIO\MutationGate\Core\Hold\HoldsAttributes;
use PhpToken;

use function preg_match_all;
use function str_replace;
use function strval;
use function trim;

/**
 * What a PHP file declares, the names it mentions, whether loading it runs
 * anything, and the paths its `#[Holds]` declare held, read from its tokens.
 * Reading it runs none of it.
 */
final readonly class PhpFile
{
    /** A fully qualified name as a string spells it, once its doubled backslashes are single. */
    private const string QUALIFIED = '/\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+/';

    /** @param list<non-empty-list<PhpToken>> $running */
    private function __construct(
        private Names $declares,
        private Names $mentions,
        private Names $quoted,
        private Names $constants,
        private array $running,
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
            self::constantsIn($tokens),
            $top->running(),
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

    /**
     * The last segment of every constant the file declares, with `const`
     * or `define()`, a class's own included: PHP resolves an unqualified
     * constant in a namespace to the global one where the namespace has
     * none, so the segment is what a file that uses it can be matched by.
     */
    public function constants(): Names
    {
        return $this->constants;
    }

    /** Whether loading the file only declares, so that it acts on nothing that does not name it. */
    public function onlyDeclares(): bool
    {
        return $this->running === [];
    }

    /**
     * The statements at the file's top that run when it is loaded, each as
     * its significant tokens.
     *
     * @return list<non-empty-list<PhpToken>>
     */
    public function running(): array
    {
        return $this->running;
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
     * A string of one segment is not read as a name, as every quoted word would
     * be: a class-string naming a class in the global namespace goes unread.
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
     * The last segment of each constant the tokens declare: each name a
     * `const` gives a value, and each a `define()` names first.
     *
     * @param list<PhpToken> $tokens
     */
    private static function constantsIn(array $tokens): Names
    {
        $names = [];
        $declaring = false;

        foreach ($tokens as $at => $token) {
            $declaring = $token->is(T_CONST) || ($declaring && ! $token->is(';'));
            $named = match (true) {
                $declaring && $token->is(T_STRING) && array_key_exists($at + 1, $tokens) && $tokens[$at + 1]->is('=')
                    => $token->text,
                self::defines($tokens, $at) => trim($tokens[$at + 2]->text, '\'"'),
                default => '',
            };
            $names = $named === '' ? $names : [...$names, self::lastSegment($named)];
        }

        return Names::of(...$names);
    }

    /**
     * Whether the tokens at this place call `define()` with a quoted name.
     *
     * @param list<PhpToken> $tokens
     */
    private static function defines(array $tokens, int $at): bool
    {
        return ltrim(mb_strtolower($tokens[$at]->text), '\\') === 'define'
            && array_key_exists($at + 2, $tokens)
            && $tokens[$at + 1]->is('(')
            && $tokens[$at + 2]->is(T_CONSTANT_ENCAPSED_STRING);
    }

    /** A name without its namespace. */
    private static function lastSegment(string $name): string
    {
        $segments = explode('\\', str_replace('\\\\', '\\', $name));

        return $segments[count($segments) - 1];
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
