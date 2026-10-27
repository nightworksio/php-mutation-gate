<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Project;

use function array_filter;
use function array_find;
use function array_map;
use function array_values;
use function is_string;
use function ltrim;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\DeclaredSuites;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;
use NightWorksIO\MutationGate\Core\Test\TestsDirectory;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\MagicConst\Dir;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

use function sprintf;

/**
 * The tests a Testo config declares, read from its code and never run: the
 * locations of each `SuiteConfig`, each a literal path from the project's
 * root, where Testo resolves them, or `__DIR__` and a literal, the config
 * being in the root. A location that is a PHP file names that file, any
 * other a directory, every PHP file under which may hold tests. A config
 * that builds a location as it runs declares nothing the gate can read
 * without running it. Testo runs every suite in one run, so none is
 * declared by its name.
 */
final readonly class TestoSuite
{
    private const string SUITE = 'Testo\\Application\\Config\\SuiteConfig';

    private const string FINDER = 'Testo\\Application\\Config\\FinderConfig';

    private const string APPLICATION = 'Testo\\Application\\Config\\ApplicationConfig';

    /** Each argument the gate reads, by its name and its position. */
    private const array LOCATION = ['location', 1];

    private const array INCLUDE = ['include', 0];

    private const array EXCLUDE = ['exclude', 1];

    private const array SUITES = ['suites', 1];

    /** What `__DIR__` is, the config being in the project's root. */
    private const string ROOT = '.';

    private const string SEPARATOR = '/';

    private const string NOT_PHP = '%s is no PHP the gate can parse, so the tests it declares cannot be read: %s';

    private const string BUILT = <<<'SAID'
        %s builds its suites' locations as it runs (line %d), so the gate cannot read them. Name each literally.
        SAID;

    /** The tests where nothing configures them: `tests`, in the project's root. */
    public static function conventional(): TestSuite
    {
        return TestSuite::conventional(self::under(TestsDirectory::conventional()));
    }

    /** The tests a Testo config, read from this file, declares. */
    public static function declaredIn(Contents $config, Path $file): TestSuite|CannotJudge
    {
        try {
            $statements = new ParserFactory()->createForNewestSupportedVersion()->parse($config->text()) ?? [];
        } catch (Error $unparsed) {
            return CannotJudge::because(sprintf(self::NOT_PHP, $file->value(), $unparsed->getMessage()));
        }

        $resolved = new NodeTraverser(new NameResolver())->traverse($statements);
        $suites = self::made($resolved, self::SUITE);

        return $suites === [] ? self::unnamed($resolved, $file) : self::located($suites, $file);
    }

    /**
     * Where no `SuiteConfig` is made: Testo's default suite, unless the
     * application's config names its suites some other way.
     *
     * @param array<Node> $statements
     */
    private static function unnamed(array $statements, Path $file): TestSuite|CannotJudge
    {
        $named = array_find(
            self::made($statements, self::APPLICATION),
            static fn(New_ $application): bool => ! self::argument($application, self::SUITES) instanceof NotGiven,
        );

        return $named instanceof New_ ? self::built($file, $named) : self::conventional();
    }

    /** @param non-empty-list<New_> $suites */
    private static function located(array $suites, Path $file): TestSuite|CannotJudge
    {
        $included = [];
        $excluded = [];

        foreach ($suites as $suite) {
            [$in, $out] = self::locations(self::argument($suite, self::LOCATION));
            $included = [...$included, ...$in];
            $excluded = [...$excluded, ...$out];
        }

        $built = array_find(
            [...$included, ...$excluded],
            static fn(Expr $path): bool => self::path($path) instanceof NotGiven,
        );

        return $built instanceof Expr
            ? self::built($file, $built)
            : self::declared(self::literal($included), self::literal($excluded));
    }

    /**
     * @param list<string> $included
     * @param list<string> $excluded
     */
    private static function declared(array $included, array $excluded): TestSuite
    {
        $paths = array_map(static fn(string $path): Path => Path::of($path), $included);

        return TestSuite::of(
            array_values(array_map(
                self::under(...),
                array_filter($paths, static fn(Path $path): bool => ! $path->isPhp()),
            )),
            self::under(TestsDirectory::conventional()),
            Paths::of(...array_filter($paths, static fn(Path $path): bool => $path->isPhp())),
            Paths::of(...array_map(static fn(string $path): Path => Path::of($path), $excluded)),
            DeclaredSuites::none(),
        );
    }

    /**
     * The paths a suite's location includes and those it leaves out: a
     * `FinderConfig`'s, or those an array lists.
     *
     * @return array{list<Expr>, list<Expr>}
     */
    private static function locations(Expr|NotGiven $location): array
    {
        return $location instanceof New_ && self::isA($location, self::FINDER)
            ? [
                self::items(self::argument($location, self::INCLUDE)),
                self::items(self::argument($location, self::EXCLUDE)),
            ]
            : [self::items($location), []];
    }

    /**
     * Each path an array lists; the expression itself where it is no array
     * of plain items, which names no path the gate can read.
     *
     * @return list<Expr>
     */
    private static function items(Expr|NotGiven $paths): array
    {
        return match (true) {
            $paths instanceof NotGiven => [],
            $paths instanceof Array_ => array_values(array_map(
                static fn(Node\ArrayItem $item): Expr => $item->key === null && ! $item->unpack ? $item->value : $paths,
                $paths->items,
            )),
            default => [$paths],
        };
    }

    /**
     * @param list<Expr> $paths
     *
     * @return list<string>
     */
    private static function literal(array $paths): array
    {
        return array_values(array_filter(array_map(self::path(...), $paths), is_string(...)));
    }

    /** A literal path from the root, `__DIR__` being the root; none where it is built as the config runs. */
    private static function path(Expr $path): string|NotGiven
    {
        return match (true) {
            $path instanceof String_ => $path->value,
            $path instanceof Dir => self::ROOT,
            $path instanceof Concat && $path->left instanceof Dir && $path->right instanceof String_
                => ltrim($path->right->value, self::SEPARATOR),
            default => NotGiven::value(),
        };
    }

    /**
     * The argument of a constructor's call by its name, or at its position; none where it is not given.
     *
     * @param array{string, int} $argument
     */
    private static function argument(New_ $call, array $argument): Expr|NotGiven
    {
        [$name, $position] = $argument;
        $given = array_find(
            array_values($call->args),
            static fn(Node $arg, int $index): bool => $arg instanceof Arg
                && ($arg->name === null ? $index === $position : $arg->name->toString() === $name),
        );

        return $given instanceof Arg ? $given->value : NotGiven::value();
    }

    /**
     * @param array<Node> $statements
     *
     * @return list<New_> each instance of this class the code makes
     */
    private static function made(array $statements, string $class): array
    {
        return array_values(array_filter(
            new NodeFinder()->findInstanceOf($statements, New_::class),
            static fn(New_ $made): bool => self::isA($made, $class),
        ));
    }

    private static function isA(New_ $made, string $class): bool
    {
        return $made->class instanceof Name && $made->class->toString() === $class;
    }

    private static function built(Path $file, Node $at): CannotJudge
    {
        return CannotJudge::because(sprintf(self::BUILT, $file->value(), $at->getStartLine()));
    }

    private static function under(Path $directory): SuiteDirectory
    {
        return SuiteDirectory::of($directory, Path::PHP);
    }
}
