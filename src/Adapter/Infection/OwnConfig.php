<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_key_exists;
use function basename;
use function file_get_contents;
use function in_array;
use function is_array;
use function is_file;
use function mb_strtolower;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function preg_split;
use function sprintf;
use function str_contains;

use Symfony\Component\Console\Input\StringInput;

/**
 * The project's own Infection config, which the config the gate writes for
 * each run starts from: its mutators, `bootstrap`, `phpUnit`,
 * `initialTestsPhpOptions` and `testFrameworkExtraArgs`. What the gate owns,
 * it writes over.
 */
final readonly class OwnConfig
{
    /** The files Infection reads its config from, in the order it looks for them. */
    private const array NAMES = ['infection.json5', 'infection.json', 'infection.json5.dist', 'infection.json.dist'];

    /**
     * The keys the gate takes from the project's config as they are. Every
     * other key either belongs to the gate or could change which mutants
     * Infection makes or how it stops, so the gate writes it itself or leaves
     * it out; the mutators and the tools' sections it writes from the
     * project's own.
     */
    private const array KEPT = [
        'bootstrap',
        'initialTestsPhpOptions',
        'testFramework',
        'staticAnalysisTool',
        'staticAnalysisToolOptions',
    ];

    /** The sections whose paths Infection resolves against the config file's directory. */
    private const array TOOLS = ['phpUnit', 'phpStan', 'mago'];

    /** The paths in each of those sections. */
    private const array PATHS = ['configDir', 'customPath'];

    /** The package of each static analysis tool Infection runs. */
    private const array ANALYSERS = ['phpstan' => 'phpstan/phpstan', 'mago' => 'carthage-software/mago'];

    private const string FRAMEWORK = 'phpunit';

    private const string PHPUNIT = 'vendor/bin/phpunit';

    private const string PHPUNIT_SECTION = 'phpUnit';

    private const string CONFIG_DIR = 'configDir';

    private const string HERE = '.';

    private const string OTHER_FRAMEWORK
        = '%s sets testFramework to %s. The gate runs Infection with PHPUnit alone, so it cannot judge that suite.';

    private const string PEST
        = '%s points phpUnit.customPath at %s. Infection cannot run Pest tests: use the Pest runner.';

    private function __construct(private string $name, private Node $settings)
    {
    }

    /** The first config file Infection would read in the project's root; with none, a config that sets nothing. */
    public static function in(Project $project): self|CannotJudge
    {
        foreach (self::NAMES as $name) {
            $file = sprintf('%s/%s', $project->root(), $name);

            if (is_file($file)) {
                return self::read($name, sprintf('%s', file_get_contents($file)));
            }
        }

        return new self(self::NAMES[0], Node::decode('{}'));
    }

    /** A config file's text, refused where the gate cannot run Infection over it. */
    public static function read(string $name, string $text): self|CannotJudge
    {
        $settings = RelaxedJson::decode($name, $text);

        return $settings instanceof CannotJudge ? $settings : self::refusing(new self($name, $settings));
    }

    /** The name of the file the config was read from. */
    public function name(): string
    {
        return $this->name;
    }

    /** The `mutators` block, which decides which mutants Infection makes. */
    public function mutators(): MutatorSettings
    {
        return MutatorSettings::of($this->settings->field('mutators'));
    }

    /** The PHPUnit Infection runs: `phpUnit.customPath`, or the project's own. */
    public function phpunit(Project $project): string
    {
        return $this->pathIn($project, self::PHPUNIT_SECTION, 'customPath', self::PHPUNIT);
    }

    /** The directory PHPUnit's config is in: `phpUnit.configDir`, or the project's root. */
    public function configDirectory(Project $project): string
    {
        return $this->pathIn($project, self::PHPUNIT_SECTION, self::CONFIG_DIR, self::HERE);
    }

    /** @return list<string> the PHP options the project's opening run takes, `initialTestsPhpOptions` */
    public function phpOptions(): array
    {
        $words = preg_split('/\s+/', $this->text('initialTestsPhpOptions'), flags: PREG_SPLIT_NO_EMPTY);

        return is_array($words) ? $words : [];
    }

    /**
     * The arguments the project passes PHPUnit, `testFrameworkExtraArgs`,
     * split as Infection splits them.
     *
     * @return list<string>
     */
    public function extraArguments(): array
    {
        $extra = $this->text('testFrameworkExtraArgs');

        return new StringInput($extra === '' ? $this->text('testFrameworkOptions') : $extra)->getRawTokens();
    }

    /**
     * The package of the static analysis tool the project's config has
     * Infection kill mutants with, `staticAnalysisTool`, if any.
     *
     * @return list<string>
     */
    public function staticAnalysis(): array
    {
        $tool = $this->text('staticAnalysisTool');

        return array_key_exists($tool, self::ANALYSERS) ? [self::ANALYSERS[$tool]] : [];
    }

    /**
     * The config the gate runs Infection with: the project's own, with every
     * path absolute, and what the gate owns written over it.
     *
     * @param list<string> $directories the source directories, by their paths on disk
     */
    public function generated(Project $project, array $directories, Seconds $cap, Mutators $mutators): string
    {
        $members = [];

        foreach ($this->entriesOf($this->settings) as $key => $value) {
            $members = [...$members, ...$this->kept($project, $key, $value, $mutators)];
        }

        $members[self::PHPUNIT_SECTION] = $this->section($project, self::PHPUNIT_SECTION, [
            self::CONFIG_DIR => ConfigJson::value($this->configDirectory($project)),
        ]);

        return ConfigJson::object([
            ...$members,
            'source' => ConfigJson::object(['directories' => ConfigJson::value($directories)]),
            'timeout' => ConfigJson::value($cap->seconds()),
            'tmpDir' => ConfigJson::value($project->own(Invocation::TMP)),
            'logs' => ConfigJson::object([
                'json' => ConfigJson::value($project->own(Invocation::JSON)),
                'text' => ConfigJson::value($project->own(Invocation::TEXT)),
            ]),
            ...$this->mutators()->narrowedTo($mutators),
        ]);
    }

    /**
     * The JSON of one key of the project's config the gate writes, by its
     * key: a kept key as it is, a tool's section with its paths absolute, the
     * mutators the run makes, and nothing for any other.
     *
     * @return array<string, string>
     */
    private function kept(Project $project, string $key, Node $value, Mutators $mutators): array
    {
        return match (true) {
            $key === 'mutators' => $this->mutators()->narrowedTo($mutators),
            in_array($key, self::KEPT, strict: true) => [$key => $value->json()],
            in_array($key, self::TOOLS, strict: true) => [$key => $this->section($project, $key, [])],
            default => [],
        };
    }

    /**
     * A tool's section of the project's config, each path in it absolute,
     * with these members written over it.
     *
     * @param array<string, string> $over
     */
    private function section(Project $project, string $tool, array $over): string
    {
        $members = [];

        foreach ($this->entriesOf($this->settings->field($tool)) as $key => $value) {
            $members[$key] = in_array($key, self::PATHS, strict: true) && self::textOf($value) !== ''
                ? ConfigJson::value($this->pathIn($project, $tool, $key, self::HERE))
                : $value->json();
        }

        return ConfigJson::object([...$members, ...$over]);
    }

    /** A path under a section of the config, absolute, or the fallback where it names none. */
    private function pathIn(Project $project, string $section, string $key, string $fallback): string
    {
        $path = self::textOf($this->settings->field($section)->field($key));

        return $project->absolute(Path::of($path === '' ? $fallback : $path));
    }

    /** The text under a key of the config, none where it holds no text. */
    private function text(string $key): string
    {
        return self::textOf($this->settings->field($key));
    }

    /** The config, or why the gate cannot run Infection over it: another test framework, or Pest as PHPUnit. */
    private static function refusing(self $config): self|CannotJudge
    {
        $framework = $config->text('testFramework');
        $custom = self::textOf($config->settings->field(self::PHPUNIT_SECTION)->field('customPath'));

        return match (true) {
            $framework !== '' && $framework !== self::FRAMEWORK => CannotJudge::because(
                sprintf(self::OTHER_FRAMEWORK, $config->name, $framework),
            ),
            str_contains(mb_strtolower(basename($custom)), 'pest') => CannotJudge::because(
                sprintf(self::PEST, $config->name, $custom),
            ),
            default => $config,
        };
    }

    /** @return array<string, Node> */
    private function entriesOf(Node $node): array
    {
        try {
            return $node->entries();
        } catch (NotInShape) {
            return [];
        }
    }

    private static function textOf(Node $node): string
    {
        try {
            return $node->text();
        } catch (NotInShape) {
            return '';
        }
    }
}
