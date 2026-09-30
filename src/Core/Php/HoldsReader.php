<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_find;
use function array_first;
use function array_key_exists;
use function array_last;
use function array_map;
use function in_array;
use function is_int;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Hold\HeldPath;
use NightWorksIO\MutationGate\Core\Hold\HoldsAttribute;
use NightWorksIO\MutationGate\Core\Hold\HoldsAttributes;
use NightWorksIO\MutationGate\Core\Hold\Standing;

use function sprintf;

/**
 * Every `#[Holds]` a PHP file writes, read from its tokens: where it stands,
 * the path it names, its line, and PHPUnit's `#[Group]` beside it. The file is
 * never loaded.
 */
final readonly class HoldsReader
{
    /** The attribute, as the package declares it. */
    private const string HOLDS = 'NightWorksIO\MutationGate\Attribute\Holds';

    /** PHPUnit's attribute that puts a test in a group. */
    private const string GROUP = 'PHPUnit\Framework\Attributes\Group';

    /** The group that holds a path. */
    private const string HOLDING_GROUP = 'holds:%s';

    /** How a name is spelt. */
    private const array NAMES = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

    /** What may stand between the attributes and what they stand on. */
    private const array MODIFIERS = [T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY, T_PUBLIC, T_PROTECTED, T_PRIVATE];

    /** What declares a class-like, whose body holds methods. */
    private const array CLASS_LIKE = [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM];

    /**
     * @param array<int, string> $bodies each class-like by where its body opens, fully qualified, or empty
     *                                   when it is anonymous
     */
    private function __construct(private Tokens $tokens, private Scope $scope, private array $bodies)
    {
    }

    public static function in(Tokens $tokens, Scope $scope): HoldsAttributes
    {
        $reader = new self($tokens, $scope, self::bodiesIn($tokens, $scope));
        $openers = $tokens->indicesOf(T_ATTRIBUTE);
        $closings = array_map($tokens->closing(...), $openers);
        $found = HoldsAttributes::none();

        foreach ($openers as $at) {
            $found = in_array($at - 1, $closings, strict: true) ? $found : $reader->heldFrom($at, $found);
        }

        return $found;
    }

    /**
     * Every class, trait, interface and enum a file declares, by where its
     * body opens: the `{` that follows its keyword at the keyword's own depth
     * before any other such keyword does.
     *
     * @return array<int, string>
     */
    private static function bodiesIn(Tokens $tokens, Scope $scope): array
    {
        $bodies = [];

        foreach ($tokens->indicesOf(...self::CLASS_LIKE) as $at) {
            $body = array_find(
                $tokens->inside($tokens->enclosing($at), '{', ...self::CLASS_LIKE),
                static fn(int $next): bool => $next > $at,
            );
            $name = $tokens->is($at + 1, T_STRING) ? $scope->declared($tokens->text($at + 1)) : '';
            $bodies = is_int($body) && $tokens->is($body, '{') && ! $tokens->is($at - 1, T_DOUBLE_COLON)
                ? $bodies + [$body => $name]
                : $bodies;
        }

        return $bodies;
    }

    /** These, with every `#[Holds]` among the attribute groups written together from an index. */
    private function heldFrom(int $start, HoldsAttributes $found): HoldsAttributes
    {
        $groups = [$start];

        while ($this->tokens->is($this->tokens->closing(array_last($groups)) + 1, T_ATTRIBUTE)) {
            $groups[] = $this->tokens->closing(array_last($groups)) + 1;
        }

        $names = [];

        foreach ($groups as $group) {
            $names = [...$names, ...$this->tokens->inside($group, ...self::NAMES)];
        }

        [$standing, $holder] = $this->standingAfter($this->tokens->closing(array_last($groups)) + 1, $start);
        $declared = $this->groupsNamedBy($names);

        foreach ($names as $at) {
            $found = $this->names($at, self::HOLDS)
                ? $found->with($this->holdsAt($at, $standing, $holder, $declared))
                : $found;
        }

        return $found;
    }

    /**
     * What the attributes that begin at one index and end before another stand
     * on, with the class, method or function it names.
     *
     * @return array{Standing, string}
     */
    private function standingAfter(int $end, int $start): array
    {
        $at = $end;

        while ($this->tokens->is($at, ...self::MODIFIERS)) {
            $at++;
        }

        return match (true) {
            $this->tokens->is($at, T_FUNCTION) => $this->functionAt($at, $start),
            $this->tokens->is($at, T_FN) => [ClosureCall::standingOf($this->tokens, $start), ''],
            $this->tokens->is($at, T_CLASS) && $this->tokens->is($at + 1, T_STRING) => [
                Standing::TestClass,
                $this->scope->declared($this->tokens->text($at + 1)),
            ],
            default => [Standing::Elsewhere, ''],
        };
    }

    /**
     * What the `function` at an index is: a closure, a method of a class, or
     * a function declared with a name.
     *
     * @return array{Standing, string}
     */
    private function functionAt(int $at, int $start): array
    {
        $name = $this->tokens->is($at + 1, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG) ? $at + 2 : $at + 1;

        if (! $this->tokens->is($name, T_STRING)) {
            return [ClosureCall::standingOf($this->tokens, $start), ''];
        }

        $body = $this->tokens->enclosing($start);

        return match (true) {
            ! array_key_exists($body, $this->bodies) => [
                Standing::NamedFunction,
                $this->scope->declared($this->tokens->text($name)),
            ],
            $this->bodies[$body] === '' => [Standing::Elsewhere, ''],
            default => [Standing::TestMethod, sprintf('%s::%s', $this->bodies[$body], $this->tokens->text($name))],
        };
    }

    /**
     * The group each of PHPUnit's `#[Group]` among some attributes names, where
     * it is one string literal.
     *
     * @param  list<int>    $names where each attribute's name stands
     * @return list<string>
     */
    private function groupsNamedBy(array $names): array
    {
        $groups = [];

        foreach ($names as $at) {
            $argument = $this->argumentOf($at);
            $groups = $this->names($at, self::GROUP) && $argument->isLiteral()
                ? [...$groups, $argument->text()]
                : $groups;
        }

        return $groups;
    }

    /** @param list<string> $declared the groups PHPUnit's `#[Group]` beside it names */
    private function holdsAt(int $at, Standing $standing, string $holder, array $declared): HoldsAttribute
    {
        $path = $this->argumentOf($at);
        $line = $this->tokens->line($at);
        $grouped = in_array(sprintf(self::HOLDING_GROUP, $path->text()), $declared, strict: true);

        return match ($standing) {
            Standing::TestClass => HoldsAttribute::onClass($path, $line, $holder, $grouped),
            Standing::TestMethod => HoldsAttribute::onMethod($path, $line, $holder, $grouped),
            Standing::NamedFunction => HoldsAttribute::onFunction($path, $line, $holder),
            Standing::TestClosure,
            Standing::DescribeClosure,
            Standing::HookClosure,
            Standing::DatasetClosure,
            Standing::KeptClosure,
            Standing::OtherClosure,
            Standing::Elsewhere => HoldsAttribute::at($standing, $path, $line),
        };
    }

    /**
     * The first argument of the attribute named at an index, named `path` or
     * not: one string literal, or anything else as it is written.
     */
    private function argumentOf(int $at): HeldPath
    {
        $open = $at + 1;

        if (! $this->tokens->is($open, '(')) {
            return HeldPath::expression('');
        }

        $end = array_first($this->tokens->inside($open, ',')) ?? $this->tokens->closing($open);
        $first = $open + 1;
        $from = $this->tokens->is($first + 1, ':') ? $first + 2 : $first;

        return $end - $from === 1 && $this->tokens->is($from, T_CONSTANT_ENCAPSED_STRING)
            ? HeldPath::literal(mb_substr($this->tokens->text($from), 1, -1))
            : HeldPath::expression($this->tokens->spelt($from, $end));
    }

    /** Whether the name at an index stands for this class. */
    private function names(int $at, string $class): bool
    {
        return $this->scope->resolve($this->tokens->text($at))->meet(Names::of($class));
    }
}
