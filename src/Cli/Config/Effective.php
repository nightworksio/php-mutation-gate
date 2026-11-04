<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use Closure;
use DateTimeImmutable;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\StartedGate;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\BuiltinPreset;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\ConfigReads;
use NightWorksIO\MutationGate\Core\Config\ContextChain;
use NightWorksIO\MutationGate\Core\Config\Definition;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Publishing;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\Setup;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Extension\Extensions;

/**
 * The effective config of a project (ADR-0002): zero-config defaults, then
 * the presets, then the config file, then the command line, each with its
 * blocks for the contexts the run is in laid over the rest of it
 * (ADR-0070). Each layer is read on its own, and what only every layer
 * together can say, such as that no runner is chosen, once all of them are
 * laid. When no layer sets the preset or the runner, zero-config finds them.
 *
 * @internal
 */
final readonly class Effective
{
    public function __construct(
        private string $project,
        private Extensions $extensions,
        private Detected $detected,
        private DateTimeImmutable $now,
        private RunContext $context,
    ) {
    }

    /** The settings of a run in the contexts it is in, each layer's blocks for them laid over the rest of it. */
    public function settings(CommandLine $given): Settings|Invalid|CannotJudge
    {
        return $this->resolved($this->file($given->config), $given);
    }

    /** The settings with each layer's context blocks kept as it writes them, laid by context (ADR-0070). */
    public function asWritten(CommandLine $given): Settings|Invalid|CannotJudge
    {
        return $this->over($this->file($given->config), $given, static fn(Layer $layer): Layer => $layer);
    }

    /** The contexts a run is in: those `--context` names, else those the environment and the CI plan tell. */
    public function contexts(CommandLine $given): ContextChain|Invalid|CannotJudge
    {
        return $this->chain($this->file($given->config), $given);
    }

    /**
     * The settings, where a config file holds what this copy holds, as it
     * stood at a base a change is read since; or why they cannot be read.
     */
    public function settingsOf(CommandLine $given, ConfigFile $copy): Settings|Invalid|CannotJudge
    {
        return $this->resolved($this->loaded($copy), $given);
    }

    /**
     * The files the config file a run reads reads beside itself, as the
     * loader of its format finds them without running it; none where the run
     * reads no config file; or why they cannot be named, where the file
     * cannot be found or its format has no loader (ADR-0005).
     */
    public function reads(CommandLine $given): ConfigReads
    {
        $path = ConfigLocation::in($this->project, $given->config);

        if (! $path instanceof Path) {
            return $path instanceof NoConfigFile ? ConfigReads::none() : ConfigReads::unnamed($path->why());
        }

        $file = $this->configFile($path);
        $loader = Formats::loader($this->extensions, $file);

        return $loader instanceof CannotJudge ? ConfigReads::unnamed($loader->why()) : $loader->reads($file);
    }

    /**
     * What the config file sets of what a project publishes and of the
     * dashboard, over every default, read on its own, so a repository that
     * builds an organisation's dashboard needs no runner (ADR-0024); or why
     * the file cannot be read.
     */
    public function publishing(CommandLine $given): Publishing|Invalid|CannotJudge
    {
        $file = $this->file($given->config);

        return $file instanceof Layer ? Layer::standard()->over($file)->publishing() : $file;
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

    /** The settings a config file's layer and the command line set, each in the contexts the run is in. */
    private function resolved(Layer|Invalid|CannotJudge $file, CommandLine $given): Settings|Invalid|CannotJudge
    {
        $chain = $this->chain($file, $given);

        return $chain instanceof ContextChain
            ? $this->over($file, $given, static fn(Layer $layer): Layer => $layer->in($chain))
            : $chain;
    }

    /** The contexts the run is in, told from the settings every layer makes without its blocks (ADR-0070). */
    private function chain(Layer|Invalid|CannotJudge $file, CommandLine $given): ContextChain|Invalid|CannotJudge
    {
        return $this->context->chain(
            $given,
            fn(): Settings|Invalid|CannotJudge => $this->over(
                $file,
                $given,
                static fn(Layer $layer): Layer => $layer->in(ContextChain::none()),
            ),
            $this->extensions,
        );
    }

    /**
     * The settings a config file's layer and the command line set, with every layer beneath them, each layer
     * resolved for the run's contexts before they are laid.
     *
     * @param Closure(Layer): Layer $resolve
     */
    private function over(
        Layer|Invalid|CannotJudge $file,
        CommandLine $given,
        Closure $resolve,
    ): Settings|Invalid|CannotJudge {
        $line = $given->layer();

        if (! $file instanceof Layer || ! $line instanceof Layer) {
            return $file instanceof Layer ? $line : $file;
        }

        $registry = new Chosen($this->extensions)->given($given, $file->setup()->extensions());

        return $registry instanceof CannotJudge
            ? $registry
            : $this->layered($resolve($file)->over($line), $registry, $resolve);
    }

    /** The config file read, or a layer that sets nothing for zero-config. */
    private function file(string|NotGiven $given): Layer|Invalid|CannotJudge
    {
        $path = ConfigLocation::in($this->project, $given);

        if (! $path instanceof Path) {
            return $path instanceof NoConfigFile ? Layer::of() : $path;
        }

        return $this->loaded($this->configFile($path));
    }

    /** A config file read by the loader of its format, and judged. */
    private function loaded(ConfigFile $file): Layer|Invalid|CannotJudge
    {
        $loader = Formats::loader($this->extensions, $file);
        $loaded = $loader instanceof CannotJudge ? $loader : $loader->load($file);

        return $loaded instanceof Layer ? Definition::judged($loaded, $file) : $loaded;
    }

    /** The presets beneath the config file and the command line, and zero-config's findings beneath those. */
    /** @param Closure(Layer): Layer $resolve */
    private function layered(Layer $written, Extensions $registry, Closure $resolve): Settings|Invalid|CannotJudge
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
        $merged = $layers->laid($resolve)->over($written);
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

    /** The config file at this path, read by the gate as it was started. */
    private function configFile(Path $path): ConfigFile
    {
        return ConfigFile::at($path, Path::of($this->project))->startedAs(StartedGate::in($this->project));
    }
}
