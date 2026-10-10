<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_any;

use Closure;

use function count;
use function mb_stripos;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Hold\Standing;
use NightWorksIO\MutationGate\Core\Test\TestDependencies;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestMethod;
use PhpToken;

/**
 * The test methods a PHP file's test methods depend on, as PHPUnit's
 * `#[Depends]` attributes name them, read from its tokens: a method of the
 * same class by its name, one of another class by `<class>::class` and its
 * name. An attribute that names its method by anything but a string literal,
 * or that names a whole class, names nothing here. The file is never loaded.
 */
final readonly class DependsReader
{
    /** PHPUnit's attributes that name a method of the same class. */
    private const array SAME_CLASS = [
        'PHPUnit\Framework\Attributes\Depends',
        'PHPUnit\Framework\Attributes\DependsUsingDeepClone',
        'PHPUnit\Framework\Attributes\DependsUsingShallowClone',
    ];

    /** PHPUnit's attributes that name a class and a method of it. */
    private const array EXTERNAL = [
        'PHPUnit\Framework\Attributes\DependsExternal',
        'PHPUnit\Framework\Attributes\DependsExternalUsingDeepClone',
        'PHPUnit\Framework\Attributes\DependsExternalUsingShallowClone',
    ];

    /** What each of the attributes' names spells, in some case. */
    private const string SPELT = 'depends';

    private function __construct(private Tokens $tokens, private Scope $scope)
    {
    }

    /**
     * What every one of these files records.
     *
     * @param Closure(Path): Contents $read what a file holds
     */
    public static function inFiles(Paths $files, Closure $read): TestDependencies
    {
        $found = TestDependencies::none();

        foreach ($files as $file) {
            $found = $found->and(self::in($read($file)));
        }

        return $found;
    }

    public static function in(Contents $contents): TestDependencies
    {
        if (mb_stripos($contents->text(), self::SPELT) === false) {
            return TestDependencies::none();
        }

        $significant = [];

        foreach (PhpToken::tokenize($contents->text()) as $token) {
            if (! $token->isIgnorable() && ! $token->is(T_CLOSE_TAG)) {
                $significant[] = $token;
            }
        }

        $tokens = Tokens::of($significant);
        $reader = new self($tokens, TopLevel::of($significant)->scope());
        $found = TestDependencies::none();

        foreach (AttributedMembers::in($tokens, $reader->scope) as $member) {
            $found = $member->standing() === Standing::TestMethod ? $reader->dependedOn($member, $found) : $found;
        }

        return $found;
    }

    /** These, with what each `#[Depends]` of a run of attributes on a test method names. */
    private function dependedOn(AttributedMember $member, TestDependencies $found): TestDependencies
    {
        $class = TestMethod::classOf(TestId::of($member->holder()));

        foreach ($member->names() as $at) {
            $named = $this->dependencyAt($at, $class);
            $found = $named === '' ? $found : $found->with($member->holder(), $named);
        }

        return $found;
    }

    /**
     * The test method the attribute named at an index depends on, as
     * `<class>::<method>`; empty where it is no `#[Depends]`, or names none
     * this reads.
     */
    private function dependencyAt(int $at, string $class): string
    {
        $written = $this->scope->resolve($this->tokens->text($at));
        $is = static fn(string $attribute): bool => $written->meet(Names::of($attribute));
        $open = $at + 1;
        $arguments = $this->tokens->is($open, '(')
            ? [...$this->tokens->inside($open, ','), $this->tokens->closing($open)]
            : [];

        return match (true) {
            $arguments === [] => '',
            array_any(self::SAME_CLASS, $is) && count($arguments) === 1 => $this->methodOf(
                $class,
                $this->literal($open + 1, $arguments[0]),
            ),
            array_any(self::EXTERNAL, $is) && count($arguments) === 2 => $this->methodOf(
                $this->classNamed($open + 1, $arguments[0]),
                $this->literal($arguments[0] + 1, $arguments[1]),
            ),
            default => '',
        };
    }

    /** A method as `<class>::<method>`; empty where either is unread. */
    private function methodOf(string $class, string $method): string
    {
        return $class === '' || $method === '' ? '' : TestMethod::id($class, $method)->value();
    }

    /** The string literal an argument is, read past a name it is passed by; empty where it is none. */
    private function literal(int $from, int $end): string
    {
        $at = $this->pastName($from);

        return $end - $at === 1 && $this->tokens->is($at, T_CONSTANT_ENCAPSED_STRING)
            ? $this->tokens->unquoted($at)
            : '';
    }

    /** The class an argument names as `<name>::class`, read past a name it is passed by; empty where it is none. */
    private function classNamed(int $from, int $end): string
    {
        $at = $this->pastName($from);

        return $at + 2 === $end - 1
            && $this->tokens->is($at, ...Names::TOKENS)
            && $this->tokens->is($at + 1, T_DOUBLE_COLON)
            && $this->tokens->is($at + 2, T_CLASS)
            ? $this->scope->className($this->tokens->text($at))
            : '';
    }

    /** Where an argument's value begins: past `name:` where it is passed by name. */
    private function pastName(int $from): int
    {
        return $this->tokens->is($from + 1, ':') ? $from + 2 : $from;
    }
}
