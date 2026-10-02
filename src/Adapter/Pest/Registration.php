<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_all;

use Closure;

use function mb_strtolower;

use NightWorksIO\MutationGate\Core\Assertion\TestAssertions;
use NightWorksIO\MutationGate\Core\Php\Argument;
use NightWorksIO\MutationGate\Core\Php\Chain;
use NightWorksIO\MutationGate\Core\Php\Link;
use NightWorksIO\MutationGate\Core\Php\PhpFile;
use NightWorksIO\MutationGate\Core\Php\TopLevel;
use PhpToken;

/**
 * The closed list of what a test file's top may run, besides declaring, and
 * leave the file inert: the Pest registrations whose effects stay in the
 * file that makes them. Pest keys each by the file it is called in: a test,
 * a group of tests, a hook, a dataset, the code a file's tests cover, and
 * the traits or case its tests use.
 *
 * A registration keeps the file inert only as one call with the methods
 * chained on it, `test(…)->with(…)->group(…);`, none of them `->in()`, whose
 * arguments run nothing as the file loads: literals, constants, class names,
 * arrays of these, and closures. Pest runs two closures as the file loads,
 * so each is held to more: a `describe()` body, whose statements must each be
 * a registration on this list in turn, and a dataset handed to `dataset()` or
 * `->with()` as a closure, which must only return or yield what runs nothing.
 * Any other closure runs as a test or a hook runs.
 *
 * A test file that runs anything else, at its top, in a `describe()` body or
 * in a dataset closure, acts on what other files find: a registration sent
 * `->in()` a directory, `pest()` and `mutates()`, which change Pest's
 * configuration, a write to `$_ENV`, the environment or `$GLOBALS`, an
 * include, a call, or any statement not on this list. Every run narrowed
 * around it loads it.
 */
enum Registration: string
{
    case Test = 'test';

    case It = 'it';

    case Todo = 'todo';

    case Arch = 'arch';

    case Describe = TestAssertions::DESCRIBE;

    case BeforeEach = 'beforeEach';

    case AfterEach = 'afterEach';

    case BeforeAll = 'beforeAll';

    case AfterAll = 'afterAll';

    case Dataset = 'dataset';

    case Covers = 'covers';

    case Uses = 'uses';

    /** The method that registers a call for a directory of files, not the one it is made in. */
    private const string ELSEWHERE = 'in';

    /** The method that hands a test its dataset, which Pest resolves as the file loads. */
    private const string WITH = 'with';

    /** Whether loading the file only declares and makes registrations on this list. */
    public static function inert(PhpFile $file): bool
    {
        return self::registersOnly($file->running());
    }

    /** @param list<non-empty-list<PhpToken>> $statements */
    private static function registersOnly(array $statements): bool
    {
        return array_all($statements, fn(array $statement): bool => self::registers($statement));
    }

    /**
     * Whether a statement is a call of a function on this list, in any case,
     * with methods chained on it, and runs nothing else as the file loads.
     *
     * @param non-empty-list<PhpToken> $statement
     */
    private static function registers(array $statement): bool
    {
        $chain = Chain::of($statement);

        if (! $chain->isChain()) {
            return false;
        }

        foreach (self::cases() as $case) {
            if (mb_strtolower($case->value) === $chain->called()->name()) {
                return $case->takes($chain->called()) && self::chains($chain->methods());
            }
        }

        return false;
    }

    /**
     * Whether this registration's arguments run nothing as the file loads but what stays in it: a `describe()`
     * body only registrations in turn, and a closure `dataset()` takes only what it gives.
     */
    private function takes(Link $call): bool
    {
        $describes = $this === self::Describe;
        $gives = $this === self::Dataset;

        return self::eachRunsNothing(
            $call,
            static fn(Argument $closure): bool => match (true) {
                $describes => self::describes($closure->body()),
                $gives => $closure->onlyGives(),
                default => true,
            },
        );
    }

    /**
     * Whether each method chained on a registration keeps it in the file,
     * and runs nothing as the file loads.
     *
     * @param list<Link> $methods
     */
    private static function chains(array $methods): bool
    {
        return array_all($methods, static fn(Link $method): bool => self::passes($method));
    }

    /**
     * Whether a chained method keeps the registration in the file, and its arguments run nothing as the file
     * loads: a closure `->with()` takes only what it gives.
     */
    private static function passes(Link $method): bool
    {
        return $method->name() !== self::ELSEWHERE && self::eachRunsNothing(
            $method,
            static fn(Argument $closure): bool => $method->name() !== self::WITH || $closure->onlyGives(),
        );
    }

    /**
     * Whether each argument of a call runs nothing as the file loads: an expression that runs nothing, or a
     * closure whose running there this allows.
     *
     * @param Closure(Argument): bool $allows
     */
    private static function eachRunsNothing(Link $call, Closure $allows): bool
    {
        return array_all(
            $call->arguments(),
            static fn(Argument $argument): bool => $argument->isClosure()
                ? $allows($argument)
                : $argument->runsNothing(),
        );
    }

    /** Whether a `describe()` body declares nothing and only makes registrations on this list. */
    private static function describes(TopLevel $body): bool
    {
        return $body->declared() === [] && self::registersOnly($body->running());
    }
}
