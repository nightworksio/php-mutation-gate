<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_all;
use function array_filter;
use function array_intersect;
use function array_key_exists;
use function array_keys;
use function array_slice;
use function array_values;
use function class_exists;

use Composer\Autoload\ClassLoader;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function getmypid;
use function interface_exists;
use function is_file;
use function is_string;
use function ltrim;
use function mb_strtolower;

use PhpToken;

use function realpath;
use function rename;
use function spl_autoload_register;
use function sprintf;
use function trait_exists;
use function var_export;

/**
 * A copy Pest serves in a run's own process in place of a class file
 * Composer autoloads, served with no `file://` wrapper (ADR-0004): an
 * autoloader put before every other loads the copy, its `__FILE__` and
 * `__DIR__` spelled as the original's, when a class the original declares is
 * first asked for. A file that declares no class, whose classes Composer
 * loads from another file or not at all, or whose class is loaded already,
 * is left to pest-plugin-mutate's wrapper, as the patch leaves the file of a
 * mutant whose mutator is pinned by source (ADR-0021). It loads none of the
 * gate's classes, whose own mutants it may serve. A test that reads the original's
 * text reads it as it is on disk, a reflection of its class names the served
 * copy's file, and a run that loads the original by its path, as a `require`
 * does, runs the original's code: the process then counts it as loaded before
 * the mutant was in place.
 */
final class ServedClasses
{
    /** The file a served copy is written to, beside the copy Pest serves. */
    private const string SERVED = '%s.served.php';

    /**
     * The file a process writes a served copy to before it renames it into
     * place, by its process id, so that processes serving the same copy side
     * by side, as Pest's parallel workers do, never load one half written.
     */
    private const string WRITTEN = '%s.%d';

    /** @var array<string, string> the file served in place of each original this process serves, by its real path */
    private static array $serving = [];

    /** Serves a copy in place of the original, where both are there and the original is such a class file. */
    public static function serve(string $original, string $copy): bool
    {
        $path = (string) realpath($original);
        $classes = self::classesIn($path);
        $copied = is_file($copy) ? file_get_contents($copy) : false;

        return $classes !== [] && is_string($copied) && self::loadsOnly($classes, $path)
            && self::served($path, $classes, sprintf(self::SERVED, $copy), $copied);
    }

    /** Forgets that this process serves a copy in place of an original, as a test that served one does once done. */
    public static function forget(string $original): void
    {
        unset(self::$serving[(string) realpath($original)]);
    }

    /** The file this process loads in place of an original: the copy it serves there, or the original itself. */
    public static function inPlaceOf(string $original): string
    {
        $path = realpath($original);

        return is_string($path) && array_key_exists($path, self::$serving) ? self::$serving[$path] : $original;
    }

    /**
     * Whether this process, serving a copy, loaded the original as well, by
     * its path, so its tests ran the original's code.
     *
     * @param list<string> $loaded every file the process loaded, as `get_included_files()` lists them
     */
    public static function bypassed(array $loaded): bool
    {
        return array_intersect(array_keys(self::$serving), $loaded) !== [];
    }

    /**
     * The class-likes a file declares, each by its name as declared, by that
     * name in lower case, as PHP compares class names; none where no file
     * is there. Read from its tokens alone, by the keywords Tokens::CLASS_LIKE
     * holds rather than through Tokens or the parser, so that serving a file
     * loads none of the gate's classes, whose own mutants it may serve.
     *
     * @return array<string, string>
     */
    private static function classesIn(string $path): array
    {
        $text = is_file($path) ? (string) file_get_contents($path) : '';
        $tokens = array_values(array_filter(
            PhpToken::tokenize($text),
            static fn(PhpToken $token): bool => ! $token->isIgnorable(),
        ));
        $namespace = '';
        $classes = [];

        foreach (array_slice($tokens, 1) as $at => $next) {
            $token = $tokens[$at];
            $namespace = $token->is(T_NAMESPACE) ? self::namespaceOf($next) : $namespace;
            $name = self::declared($token, $next, $namespace);
            $classes += $name !== '' ? [mb_strtolower($name) => $name] : [];
        }

        return $classes;
    }

    /** The class-like a keyword and the token after it declare in a namespace, by its full name; none for another. */
    private static function declared(PhpToken $token, PhpToken $next, string $namespace): string
    {
        return $token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && $next->is(T_STRING)
            ? ltrim(sprintf('%s\\%s', $namespace, $next->text), '\\')
            : '';
    }

    /** The namespace a `namespace` keyword declares, by the token after it; the global one for a block. */
    private static function namespaceOf(PhpToken $named): string
    {
        return $named->is([T_STRING, T_NAME_QUALIFIED]) ? $named->text : '';
    }

    /**
     * Writes the copy's code, spelled at the original, whole to the file
     * served in its place, and loads that file, before any other autoloader,
     * the first time one of these classes is asked for.
     *
     * @param array<string, string> $classes each by its name as declared, by that name in lower case
     */
    private static function served(string $path, array $classes, string $served, string $code): bool
    {
        $written = sprintf(self::WRITTEN, $served, getmypid());

        if (file_put_contents($written, self::spelledAt($path, $code)) === false || ! rename($written, $served)) {
            return false;
        }

        spl_autoload_register(
            static function (string $class) use ($classes, $served): void {
                if (array_key_exists(mb_strtolower($class), $classes)) {
                    require_once $served;
                }
            },
            prepend: true,
        );

        self::$serving[$path] = $served;

        return true;
    }

    /**
     * Whether Composer loads each of these classes from this file, and none
     * of them is loaded yet.
     *
     * @param non-empty-array<string, string> $classes each by its name as declared
     */
    private static function loadsOnly(array $classes, string $path): bool
    {
        return array_all(
            $classes,
            static fn(string $class): bool => ! self::isLoaded($class) && self::isAutoloadedFrom($class, $path),
        );
    }

    /** Whether a class-like is loaded: an enum is loaded as a class is. */
    private static function isLoaded(string $class): bool
    {
        return class_exists($class, autoload: false)
            || interface_exists($class, autoload: false)
            || trait_exists($class, autoload: false);
    }

    private static function isAutoloadedFrom(string $class, string $path): bool
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $file = $loader->findFile($class);

            if (is_string($file)) {
                return realpath($file) === $path;
            }
        }

        return false;
    }

    /** A copy's code, its `__FILE__` and `__DIR__` spelled as the original's. */
    private static function spelledAt(string $path, string $code): string
    {
        $spelled = '';

        foreach (PhpToken::tokenize($code) as $token) {
            $spelled .= match (true) {
                $token->is(T_FILE) => var_export($path, return: true),
                $token->is(T_DIR) => var_export(dirname($path), return: true),
                default => $token->text,
            };
        }

        return $spelled;
    }
}
