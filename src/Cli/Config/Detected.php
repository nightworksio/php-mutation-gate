<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use function array_any;
use function array_map;
use function array_pop;
use function count;
use function implode;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\ComposerVendor;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\Composer\Names;
use NightWorksIO\MutationGate\Core\Composer\Package;
use NightWorksIO\MutationGate\Core\Config\BuiltinAnalyser;
use NightWorksIO\MutationGate\Core\Config\BuiltinPreset;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\StaticCheck;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

use function sprintf;

/**
 * What zero-config finds out about a project (ADR-0002): its preset, from
 * what its `composer.json` requires, and its runner and static analyser,
 * from what is installed.
 */
final readonly class Detected
{
    /** Why two runners installed side by side leave the choice to a person. */
    private const string BOTH = 'Both %s and %s are installed. Choose one: set runner in the config, or pass --runner.';

    /** Why more runners than two installed side by side leave the choice to a person. */
    private const string SEVERAL = '%s and %s are installed. Choose one: set runner in the config, or pass --runner.';

    /** Why Pest alone, without its mutation plugin, leaves nothing to mutate with. */
    private const string PEST_WITHOUT_PLUGIN
        = '%s is installed without %s, so nothing can mutate its tests. Install %2$s, or set runner in the config.';

    /** The package that says each preset fits, in the order they are asked about (ADR-0008). */
    private const array PRESETS = [
        Package::Laravel->value => BuiltinPreset::Laravel,
        Package::Symfony->value => BuiltinPreset::Symfony,
    ];

    public function __construct(private Directory $project, private Directory $vendor)
    {
    }

    /** Laravel when `composer.json` requires `laravel/framework`, Symfony for the bundle, else a library. */
    public function preset(): BuiltinPreset|CannotJudge
    {
        $contents = $this->project->read(Manifest::fileIn(Path::root()));
        $manifest = $contents instanceof Contents ? Manifest::decode($contents, Path::root()) : $contents;

        if ($manifest instanceof CannotJudge) {
            return $manifest;
        }

        $required = $manifest instanceof Manifest ? $manifest->requiresToRun() : Names::of();

        foreach (self::PRESETS as $package => $preset) {
            if ($required->has($package)) {
                return $preset;
            }
        }

        return BuiltinPreset::Library;
    }

    /**
     * Pest when its mutation plugin is installed, Infection when Infection
     * is, and both is a choice to make; where neither is, and Pest is not,
     * the one native framework the project uses, and more than one is a
     * choice to make (ADR-0023, ADR-0027).
     */
    public function runner(): BuiltinRunner|CannotJudge
    {
        $installed = $this->installed();

        return $installed instanceof CannotJudge ? $installed : $this->runnerIn($installed);
    }

    /**
     * The analyser `staticCheck.tool: auto` takes: the first whose command is
     * installed and whose config the project has, or `none`. `doctor` asks
     * this too, so the two can never disagree.
     */
    public function staticChecker(): Name
    {
        foreach (BuiltinAnalyser::cases() as $analyser) {
            if ($this->installs($analyser->value) && $this->hasAny($analyser->configs())) {
                return Name::of($analyser->value);
            }
        }

        return Name::of(StaticCheck::NONE);
    }

    /** What Composer installed in the vendor directory, as its `installed.json` lists it. */
    public function installed(): Installed|CannotJudge
    {
        $file = Installed::fileIn(Path::root());
        $contents = $this->vendor->read($file);

        return match (true) {
            $contents instanceof Contents => Installed::decode($contents, $file),
            $contents instanceof CannotJudge => $contents,
            default => Installed::missingAt($file),
        };
    }

    /**
     * The runners a person must choose between, since zero-config chooses
     * none: Pest's mutation plugin and Infection, both installed; or, where
     * neither is and Pest is not, the native frameworks the project uses,
     * where it uses more than one.
     *
     * @return list<BuiltinRunner> none where zero-config chooses, or nothing is installed
     */
    public function choices(): array
    {
        $installed = $this->installed();

        return $installed instanceof Installed ? $this->choicesIn($installed) : [];
    }

    /** @return list<BuiltinRunner> */
    private function choicesIn(Installed $installed): array
    {
        $pest = $installed->has(Package::PestMutate->value);
        $infection = $installed->has(Package::Infection->value);
        $native = $pest || $infection || $installed->has(Package::Pest->value) ? [] : $this->nativeIn($installed);

        return match (true) {
            $pest && $infection => [BuiltinRunner::Pest, BuiltinRunner::Infection],
            count($native) > 1 => $native,
            default => [],
        };
    }

    /**
     * The native frameworks the project uses: each one installed, but
     * PHPUnit beside Codeception, which installs it, only where the project
     * requires PHPUnit itself.
     *
     * @return list<BuiltinRunner>
     */
    private function nativeIn(Installed $installed): array
    {
        $codeception = $installed->has(Package::Codeception->value);
        $phpunit = $installed->has(Package::PhpUnit->value) && (! $codeception || $this->requires(Package::PhpUnit));

        return [
            ...$phpunit ? [BuiltinRunner::PhpUnit] : [],
            ...$installed->has(Package::PhpSpec->value) ? [BuiltinRunner::PhpSpec] : [],
            ...$codeception ? [BuiltinRunner::Codeception] : [],
        ];
    }

    /** Whether the project's `composer.json` requires a package, to run or be developed; not where it is unread. */
    private function requires(Package $package): bool
    {
        $contents = $this->project->read(Manifest::fileIn(Path::root()));
        $manifest = $contents instanceof Contents ? Manifest::decode($contents, Path::root()) : $contents;

        return $manifest instanceof Manifest && $manifest->requires()->has($package->value);
    }

    /**
     * Why zero-config chooses none of these runners, each installed.
     *
     * @param list<BuiltinRunner> $choices two or more
     */
    private static function chooseBetween(array $choices): CannotJudge
    {
        $names = array_map(static fn(BuiltinRunner $runner): string => $runner->package()->value, $choices);
        $last = (string) array_pop($names);

        return CannotJudge::because(
            count($names) === 1
                ? sprintf(self::BOTH, implode('', $names), $last)
                : sprintf(self::SEVERAL, implode(', ', $names), $last),
        );
    }

    private function runnerIn(Installed $installed): BuiltinRunner|CannotJudge
    {
        $choices = $this->choicesIn($installed);
        $native = $this->nativeIn($installed);

        return match (true) {
            $choices !== [] => self::chooseBetween($choices),
            $installed->has(Package::PestMutate->value) => BuiltinRunner::Pest,
            $installed->has(Package::Infection->value) => BuiltinRunner::Infection,
            $installed->has(Package::Pest->value) => CannotJudge::because(
                sprintf(self::PEST_WITHOUT_PLUGIN, Package::Pest->value, Package::PestMutate->value),
            ),
            $native !== [] => $native[0],
            default => CannotJudge::because(sprintf(
                'None of %s, %s, %s, %s and %s is installed, so nothing can mutate. Install one of them.',
                Package::PestMutate->value,
                Package::Infection->value,
                Package::PhpUnit->value,
                Package::PhpSpec->value,
                Package::Codeception->value,
            )),
        };
    }

    /** Whether Composer linked this command into the vendor directory. */
    private function installs(string $command): bool
    {
        return $this->vendor->read(Path::of(ComposerVendor::BIN)->child(Path::of($command))) instanceof Contents;
    }

    /** Whether the project's root has any of these files. */
    private function hasAny(Paths $files): bool
    {
        return array_any([...$files], fn(Path $file): bool => $this->project->read($file) instanceof Contents);
    }
}
