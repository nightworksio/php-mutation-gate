<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Project;

use function array_key_exists;
use function array_map;
use function basename;
use function file_get_contents;
use function glob;
use function is_array;
use function is_file;
use function is_string;
use function json_encode;

use JsonException;

use function ksort;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Runner\CodeceptionSuiteSettings;
use NightWorksIO\MutationGate\Core\Test\DeclaredSuites;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;
use NightWorksIO\MutationGate\Core\Test\TestsDirectory;

use function preg_replace;
use function sprintf;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The tests a Codeception config declares, read with `symfony/yaml`, which
 * Codeception requires: each suite's directory under the config's
 * `paths.tests`, at the suite's `path` or its name, whose files of tests
 * end in `Test.php`, `Cest.php` or `Cept.php`. The suites are those the
 * config sets under `suites`, and those of a `<name>.suite.yml` or
 * `<name>.suite.dist.yml` in the tests directory. A suite that serves the
 * application from another process holds no test the gate runs (see
 * CodeceptionSuiteSettings), and is named among the suites it leaves out.
 * Codeception runs every suite in one run, so none is declared by its name.
 */
final readonly class CodeceptionSuite
{
    /** The suffixes Codeception names a file of tests with: a unit test, as PHPUnit does, a Cest and a Cept. */
    private const array SUFFIXES = ['', 'Cest.php', 'Cept.php'];

    /** Every suite's own config in the tests directory, in the order a suite's first found wins. */
    private const array SUITE_FILES = ['%s/*.suite.yml', '%s/*.suite.dist.yml'];

    /** What a suite's own config's name ends in, after the suite's name. */
    private const string SUITE_FILE = '/\.suite(\.dist)?\.yml$/';

    private const string NOT_YAML = '%s is not YAML, so the tests it declares cannot be read: %s';

    private const string UNREAD = 'The Codeception suite config %s could not be read.';

    /** The tests where nothing configures them: in `tests`. */
    public static function conventional(): TestSuite
    {
        $directories = self::under(TestsDirectory::conventional());

        return TestSuite::of($directories, $directories[0], Paths::none(), Paths::none(), DeclaredSuites::none());
    }

    /** The tests a Codeception config, read from this file in a project's root, declares. */
    public static function declaredIn(Contents $config, Path $file, Root $root): TestSuite|CannotJudge
    {
        $read = self::yaml($config->text(), $file->value());

        return $read instanceof CannotJudge ? $read : self::suiteOf($read, $root);
    }

    private static function suiteOf(Node $config, Root $root): TestSuite|CannotJudge
    {
        $named = Lenient::text($config->field('paths')->field('tests'));
        $tests = $named === '' ? TestsDirectory::conventional() : Path::of($named);
        $directories = [];
        $elsewhere = [];

        foreach (self::suitesOf($config, $root->at($tests)->value()) as $name => $settings) {
            if ($settings instanceof CannotJudge) {
                return $settings;
            }

            if (self::servedElsewhere($config, $settings)) {
                $elsewhere[] = $name;

                continue;
            }

            $directories = [...$directories, ...self::suiteDirectories($tests, $name, $settings)];
        }

        return TestSuite::of(
            $directories,
            self::under($tests)[0],
            Paths::none(),
            Paths::none(),
            DeclaredSuites::none(),
        )->leaving(...$elsewhere);
    }

    /** Whether a suite, or the config every suite takes, serves the application from another process. */
    private static function servedElsewhere(Node $config, Node $settings): bool
    {
        return CodeceptionSuiteSettings::servesElsewhere($config)
            || CodeceptionSuiteSettings::servesElsewhere($settings);
    }

    /**
     * A suite's directories, at its path or its name under the tests directory.
     *
     * @return non-empty-list<SuiteDirectory>
     */
    private static function suiteDirectories(Path $tests, string $name, Node $settings): array
    {
        $path = Lenient::text($settings->field('path'));

        return self::under($tests->child(Path::of($path === '' ? $name : $path)));
    }

    /**
     * Each suite's settings, by its name: those the config sets under
     * `suites`, else those of its own file in the tests directory.
     *
     * @return array<string, Node|CannotJudge>
     */
    private static function suitesOf(Node $config, string $directory): array
    {
        $suites = [];

        foreach (Lenient::entries($config->field('suites')) as $name => $settings) {
            $suites[sprintf('%s', $name)] = $settings;
        }

        foreach (self::ownFilesIn($directory) as $file) {
            $name = sprintf('%s', preg_replace(self::SUITE_FILE, '', basename($file)));
            $suites[$name] = array_key_exists($name, $suites) ? $suites[$name] : self::read($file);
        }

        ksort($suites);

        return $suites;
    }

    /**
     * Every suite's own config in the tests directory, a suite's own file
     * before its dist file.
     *
     * @return list<string>
     */
    private static function ownFilesIn(string $directory): array
    {
        $files = [];

        foreach (self::SUITE_FILES as $pattern) {
            $found = glob(sprintf($pattern, $directory));
            $files = [...$files, ...is_array($found) ? $found : []];
        }

        return $files;
    }

    private static function read(string $file): Node|CannotJudge
    {
        $text = is_file($file) ? file_get_contents($file) : false;

        return is_string($text)
            ? self::yaml($text, basename($file))
            : CannotJudge::because(sprintf(self::UNREAD, $file));
    }

    private static function yaml(string $text, string $file): Node|CannotJudge
    {
        try {
            return Node::decode(json_encode(Yaml::parse($text), JsonText::FLAGS));
        } catch (ParseException|JsonException $unparsed) {
            return CannotJudge::because(sprintf(self::NOT_YAML, $file, $unparsed->getMessage()));
        }
    }

    /** @return non-empty-list<SuiteDirectory> a directory, once for each suffix of a file of tests */
    private static function under(Path $directory): array
    {
        return array_map(
            static fn(string $suffix): SuiteDirectory => SuiteDirectory::of($directory, $suffix),
            self::SUFFIXES,
        );
    }
}
