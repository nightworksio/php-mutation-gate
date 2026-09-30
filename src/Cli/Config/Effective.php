<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use function count;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Registry\Lookup;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Definition\At;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\Setup;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
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
        $file = $this->file($given->config);
        $line = $given->layer();

        if (! $file instanceof Layer || ! $line instanceof Layer) {
            return $file instanceof Layer ? $line : $file;
        }

        $registry = $given->firstPartyOnly
            ? $this->extensions
            : new Chosen($this->extensions)->withExtensions($file->setup()->extensions(), 'the config file');

        return $registry instanceof CannotJudge ? $registry : $this->layered($file->over($line), $registry);
    }

    /** The config file read, or a layer that sets nothing for zero-config. */
    private function file(string $given): Layer|Invalid|CannotJudge
    {
        $path = ConfigLocation::in($this->project, $given);

        if (! $path instanceof Path) {
            return $path instanceof Absent ? Layer::none() : $path;
        }

        $file = ConfigFile::at($path, Path::of($this->project));
        $loader = Formats::loader($this->extensions, $file);

        return $loader instanceof CannotJudge ? $loader : $loader->load($file);
    }

    /** The presets beneath the config file and the command line, and zero-config's findings beneath those. */
    private function layered(Layer $written, Extensions $registry): Settings|Invalid|CannotJudge
    {
        $named = $written->setup()->presets();
        $detected = $named instanceof Absent ? $this->detected->preset() : '';

        if ($detected instanceof CannotJudge) {
            return $detected;
        }

        $presets = $detected === '' ? $this->named($named) : ['preset' => $detected];
        [$layers, $problems] = $this->presetLayers($presets, $registry);
        $laid = Layer::none();

        foreach ($layers as $layer) {
            $laid = $laid->over($layer);
        }

        $merged = $laid->over($written);
        $base = $this->base($merged, $detected);
        $settings = $base instanceof Layer ? Settings::settled($base->over($merged), $this->now) : $base;

        return $problems === [] ? $settings : $this->joined($problems, $settings);
    }

    /**
     * The presets a layer names, by the path it names each at: `preset` for one, `preset[1]` in a list.
     *
     * @param  Listed<string>|Absent $named
     * @return array<string, string>
     */
    private function named(Listed|Absent $named): array
    {
        $presets = $named instanceof Listed ? [...$named] : [];

        if (count($presets) === 1) {
            return ['preset' => $presets[0]];
        }

        $paths = [];

        foreach ($presets as $index => $preset) {
            $paths[At::index('preset', $index)] = $preset;
        }

        return $paths;
    }

    /**
     * The layer of each preset, in order, and a problem at its path for each one nothing registered.
     *
     * @param  array<string, string>             $presets by the path the config names each at
     * @return array{list<Layer>, list<Problem>}
     */
    private function presetLayers(array $presets, Extensions $registry): array
    {
        $layers = [];
        $problems = [];

        foreach ($presets as $path => $preset) {
            $layer = Lookup::in($registry)->preset(Name::of($preset));

            if ($layer instanceof Layer) {
                $layers[] = $layer;

                continue;
            }

            $problems[] = Problem::at($path, $layer->why());
        }

        return [$layers, $problems];
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

    /** What zero-config finds, beneath every layer: the preset it chose, and the runner when nothing chooses one. */
    private function base(Layer $merged, string $preset): Layer|CannotJudge
    {
        $runner = $merged->setup()->runner() instanceof Choice ? '' : $this->detected->runner();

        if ($runner instanceof CannotJudge) {
            return $runner;
        }

        return Layer::of(Setup::of(
            presets: $preset === '' ? Absent::setting() : Listed::of([$preset]),
            runner: $runner === '' ? Absent::setting() : Choice::of($runner, Json::object()),
        ));
    }
}
