<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_all;
use function array_any;
use function array_key_exists;
use function ltrim;
use function mb_strtolower;

use NightWorksIO\MutationGate\Core\Assertion\TestAssertions;
use NightWorksIO\MutationGate\Core\Php\PhpFile;
use PhpToken;

/**
 * The closed list of what a test file's top may run, besides declaring, and
 * leave the file inert: the Pest registrations whose effects stay in the
 * file that makes them. Pest keys each by the file it is called in: a test,
 * a group of tests, a hook, a dataset, the code a file's tests cover, and
 * the traits or case its tests use. A test file whose top runs anything else
 * acts on what other files find: a registration sent `->in()` a directory,
 * `pest()` and `mutates()`, which change Pest's configuration, a write to
 * `$_ENV`, the environment or `$GLOBALS`, an include, or any statement not on
 * this list. Every run narrowed around it loads it.
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

    /** Whether loading the file only declares and makes registrations on this list, none of them `->in()`. */
    public static function inert(PhpFile $file): bool
    {
        return array_all(
            $file->running(),
            /** @param non-empty-list<PhpToken> $statement */
            static fn(array $statement): bool => self::registers($statement),
        );
    }

    /**
     * Whether a statement calls a function on this list, as PHP spells it in
     * any case, and sends nothing it returns `->in()`.
     *
     * @param non-empty-list<PhpToken> $statement
     */
    private static function registers(array $statement): bool
    {
        $called = mb_strtolower(ltrim($statement[0]->text, '\\'));
        $listed = $statement[0]->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && array_any(
            self::cases(),
            static fn(self $case): bool => mb_strtolower($case->value) === $called,
        );

        return $listed && array_key_exists(1, $statement) && $statement[1]->is('(') && ! self::sendsIn($statement);
    }

    /** @param non-empty-list<PhpToken> $statement */
    private static function sendsIn(array $statement): bool
    {
        foreach ($statement as $at => $token) {
            $sent = $token->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])
                && array_key_exists($at + 2, $statement)
                && mb_strtolower($statement[$at + 1]->text) === self::ELSEWHERE
                && $statement[$at + 2]->is('(');

            if ($sent) {
                return true;
            }
        }

        return false;
    }
}
