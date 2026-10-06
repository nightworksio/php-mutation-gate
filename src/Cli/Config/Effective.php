<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\BuiltinPreset;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Definition;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\Setup;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Extension\Extensions;

/**
 * The effective config of a project (ADR-0002): zero-config defaults, then
 * the presets, then the config file, then the command line. Each layer is
 * read on its own, and what only every layer together can say, such as that
 * no runner is chosen, once all of them are laid. When no layer sets the
 * preset or the runner, zero-config finds them.
 */
final readonly class Effective
{
    public function __construct(
        private string $project,
        private Extensions $extensions,
        private Detected $detected,
        private DateTimeImmutable $now,
    ) {
    }

    public function settings(CommandLine $given): Settings|Invalid|CannotJudge
    {
        return $this->over($this->file($given->config), $given);
    }

    /**
     * The settings that affect results, serialised canonically (ADR-0007),
     * where a config file holds what this copy holds, as it stood at a base
     * a change is read since (ADR-0005, decision 4); or why they cannot be
     * read.
     */
    public function canonicalOf(CommandLine $given, ConfigFile $copy): string|Invalid|CannotJudge
    {
        $settings = $this->over($this->loaded($copy), $given);

        return $settings instanceof Settings ? $settings->canonical() : $settings;
    }

    /** Whether the config file or the command line chooses the runner, rather than leave it to zero-config. */
    public function choosesRunner(CommandLine $given): bool
    {
        $file = $this->file($given->config);
        $line = $given->layer();

        return $file instanceof Layer
            && $line instanceof Layer
            && $file->over($line)->setup()->runner() instanceof Choice;
    }

    /** The settings a config file's layer and the command line set, with every layer beneath them. */
    private function over(Layer|Invalid|CannotJudge $file, CommandLine $given): Settings|Invalid|CannotJudge
    {
        $line = $given->layer();

        if (! $file instanceof Layer || ! $line instanceof Layer) {
            return $file instanceof Layer ? $line : $file;
        }

        $registry = $given->firstPartyOnly
            ? $this->extensions
            : new Chosen($this->extensions)->withExtensions($file->setup()->extensions());

        return $registry instanceof CannotJudge ? $registry : $this->layered($file->over($line), $registry);
    }

    /** The config file read, or a layer that sets nothing for zero-config. */
    private function file(string|NotGiven $given): Layer|Invalid|CannotJudge
    {
        $path = ConfigLocation::in($this->project, $given);

        if (! $path instanceof Path) {
            return $path instanceof NoConfigFile ? Layer::none() : $path;
        }

        return $this->loaded(ConfigFile::at($path, Path::of($this->project)));
    }

    /** A config file read by the loader of its format, and judged. */
    private function loaded(ConfigFile $file): Layer|Invalid|CannotJudge
    {
        $loader = Formats::loader($this->extensions, $file);
        $loaded = $loader instanceof CannotJudge ? $loader : $loader->load($file);

        return $loaded instanceof Layer ? Definition::judged($loaded, $file) : $loaded;
    }

    /** The presets beneath the config file and the command line, and zero-config's findings beneath those. */
    private function layered(Layer $written, Extensions $registry): Settings|Invalid|CannotJudge
    {
        $named = $written->setup()->presets();
        $detected = $named instanceof Absent ? $this->detected->preset() : Absent::setting();

        if ($detected instanceof CannotJudge) {
            return $detected;
        }

        $found = $detected instanceof BuiltinPreset ? Listed::of($detected->value) : $detected;
        $presets = $found instanceof Listed ? $found : $named;
        $layers = PresetLayers::named($presets, $registry);
        $problems = $layers->problems();
        $merged = $layers->laid()->over($written);
        $base = $this->base($merged, $found);
        $settings = $base instanceof Layer ? Settings::settled($base->over($merged), $this->now) : $base;

        return $problems === [] ? $settings : $this->joined($problems, $settings);
    }

    /**
     * Every problem with the config at once: those of its presets, then the rest.
     *
     * @param list<Problem> $problems
     */
    private function joined(array $problems, Settings|Invalid|CannotJudge $settings): Invalid
    {
        $rest = match (true) {
            $settings instanceof Invalid => [...$settings],
            $settings instanceof CannotJudge => [Problem::at('', $settings->why())],
            default => [],
        };

        return Invalid::because(...$problems, ...$rest);
    }

    /**
     * What zero-config finds, beneath every layer: the preset it found where no layer names one, and the runner
     * where no layer chooses one.
     *
     * @param Listed<string>|Absent $presets the preset zero-config found, or absent where a layer names its own
     */
    private function base(Layer $merged, Listed|Absent $presets): Layer|CannotJudge
    {
        $runner = $merged->setup()->runner() instanceof Choice ? Absent::setting() : $this->detected->runner();

        if ($runner instanceof CannotJudge) {
            return $runner;
        }

        return Layer::of(Setup::of(
            presets: $presets,
            runner: $runner instanceof BuiltinRunner ? Choice::of($runner->value, Options::none()) : $runner,
        ));
    }
}
