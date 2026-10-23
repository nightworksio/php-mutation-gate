<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Project;

use function json_encode;

use JsonException;

use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Test\DeclaredSuites;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;

use function sprintf;
use function str_replace;
use function str_starts_with;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

use function trim;

/**
 * The specs a PhpSpec config declares, read with `symfony/yaml`, which
 * PhpSpec requires: each suite's specs under its `spec_path`, its
 * `spec_prefix` and its `namespace` less its `psr4_prefix`, as PhpSpec's
 * own locator places them, each file of specs ending in `Spec.php`. PhpSpec runs every suite in one run, so none is
 * declared by its name.
 */
final readonly class PhpSpecSuite
{
    /** The suffix PhpSpec names a file of specs with. */
    private const string SUFFIX = 'Spec.php';

    /** Where a suite's specs are under, where it names nothing: PhpSpec's defaults. */
    private const string SPEC_PATH = '.';

    private const string SPEC_PREFIX = 'spec';

    /** What PhpSpec reads in a suite's setting as the directory of its config, which the gate reads in the root. */
    private const string CONFIG_DIRECTORY = '%paths.config%';

    /** What PhpSpec trims off each end of a namespace it reads. */
    private const string SEPARATORS = ' \\';

    private const string NOT_YAML = '%s is not YAML, so the specs it declares cannot be read: %s';

    /** The specs where nothing configures them: `spec`, in the project's root. */
    public static function conventional(): TestSuite
    {
        return TestSuite::conventional(self::under(self::SPEC_PATH, self::SPEC_PREFIX));
    }

    /** The specs a PhpSpec config, read from this file, declares. */
    public static function declaredIn(Contents $config, Path $file): TestSuite|CannotJudge
    {
        try {
            $read = Node::decode(json_encode(Yaml::parse($config->text()), JsonText::FLAGS));
        } catch (ParseException|JsonException $unparsed) {
            return CannotJudge::because(sprintf(self::NOT_YAML, $file->value(), $unparsed->getMessage()));
        }

        $directories = [];

        foreach (Lenient::entries($read->field('suites')) as $suite) {
            $directories[] = self::under(
                self::setting($suite->field('spec_path'), self::SPEC_PATH),
                self::setting($suite->field('spec_prefix'), self::SPEC_PREFIX),
                self::within($suite),
            );
        }

        return TestSuite::of(
            $directories,
            self::under(self::SPEC_PATH, self::SPEC_PREFIX),
            Paths::none(),
            Paths::none(),
            DeclaredSuites::none(),
        );
    }

    /** Where PhpSpec's locator keeps a suite's specs: under its path, its prefix's directory, then its namespace's. */
    private static function under(string $path, string $prefix, string $namespace = ''): SuiteDirectory
    {
        return SuiteDirectory::of(Path::of(sprintf('%s/%s/%s', $path, $prefix, $namespace)), self::SUFFIX);
    }

    /** A suite's namespace less its PSR-4 prefix, which PhpSpec keeps its specs under; none where it has none. */
    private static function within(Node $suite): string
    {
        $namespace = sprintf('%s\\', trim(Lenient::text($suite->field('namespace')), self::SEPARATORS));
        $prefix = sprintf('%s\\', trim(Lenient::text($suite->field('psr4_prefix')), self::SEPARATORS));

        return $prefix !== '\\' && str_starts_with($namespace, $prefix)
            ? mb_substr($namespace, mb_strlen($prefix))
            : $namespace;
    }

    /** A suite's setting as PhpSpec reads it, its config's directory the root; the default where it sets none. */
    private static function setting(Node $place, string $default): string
    {
        return $place->kind() === Kind::Text
            ? str_replace(self::CONFIG_DIRECTORY, self::SPEC_PATH, $place->text())
            : $default;
    }
}
