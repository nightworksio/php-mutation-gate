<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Cli\Registry\Lookup;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Definition\Json;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layers;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\Validator;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Extension\Extensions;

/**
 * The effective config of a project (ADR-0002): zero-config defaults, then
 * the presets, then the config file, then the command line, validated once.
 * When no layer sets the preset or the runner, zero-config finds them.
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

    public function settings(Given $given): Settings|Invalid|CannotJudge
    {
        $file = $this->file($given->config);

        if ($file instanceof CannotJudge) {
            return $file;
        }

        $registry = $given->firstPartyOnly
            ? $this->extensions
            : new Chosen($this->extensions)->withExtensions(Written::strings($file, 'extensions'), 'the config file');

        return $registry instanceof CannotJudge ? $registry : $this->layered($file, $given, $registry);
    }

    /** The config file read, or an empty one for zero-config. */
    private function file(string $given): Document|CannotJudge
    {
        $file = ConfigFile::in($this->project, $given);

        if (! $file instanceof Path) {
            return $file instanceof Absent ? $this->document([]) : $file;
        }

        $loader = Formats::loader($this->extensions, $file);
        return $loader instanceof CannotJudge ? $loader : $loader->load($file);
    }

    private function layered(Document $file, Given $given, Extensions $registry): Settings|Invalid|CannotJudge
    {
        $named = Written::presets($file);
        $detected = $named === [] ? $this->detected->preset() : '';

        if ($detected instanceof CannotJudge) {
            return $detected;
        }

        [$layers, $problems] = $this->presetLayers($detected === '' ? $named : ['preset' => $detected], $registry);
        $settings = $this->validated([...$layers, $file, $this->document($given->layer())], $detected);

        return $problems === [] ? $settings : $this->joined($problems, $settings);
    }

    /**
     * The layer of each preset, in order, and a problem at its path for each one nothing registered.
     *
     * @param  array<string, string>                   $presets by the path the config names each at
     * @return array{list<Document>, list<Problem>}
     */
    private function presetLayers(array $presets, Extensions $registry): array
    {
        $layers = [];
        $problems = [];

        foreach ($presets as $path => $preset) {
            $layer = Lookup::in($registry)->preset(Name::of($preset));

            if ($layer instanceof Document) {
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

    /** @param list<Document|CannotJudge> $layers */
    private function validated(array $layers, string $preset): Settings|Invalid|CannotJudge
    {
        $merged = $this->document([]);

        foreach ($layers as $layer) {
            $merged = match (true) {
                $merged instanceof CannotJudge => $merged,
                $layer instanceof CannotJudge => $layer,
                default => Layers::over($merged, $layer),
            };
        }

        $base = $merged instanceof Document ? $this->base($merged, $preset) : $merged;

        return $base instanceof Document ? new Validator($this->now)->validate($base) : $base;
    }

    /** What zero-config finds, beneath every layer: the preset it chose, and the runner when nothing sets one. */
    private function base(Document $merged, string $preset): Document|CannotJudge
    {
        $base = $preset === '' ? [] : ['preset' => $preset];
        $runner = Written::has($merged, 'runner') ? '' : $this->detected->runner();

        if ($runner instanceof CannotJudge) {
            return $runner;
        }

        $layer = $this->document($runner === '' ? $base : [...$base, 'runner' => $runner]);

        return $layer instanceof Document ? Layers::over($layer, $merged) : $layer;
    }

    /** @param array<mixed> $tree */
    private function document(array $tree): Document|CannotJudge
    {
        return Document::ofJson(Json::encode(Json::object($tree)));
    }
}
