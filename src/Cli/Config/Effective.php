<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use function array_key_exists;

use DateTimeImmutable;

use function is_array;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Definition\Json;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layers;
use NightWorksIO\MutationGate\Core\Config\Name;
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
            : new Chosen($this->extensions)->withExtensions($this->strings($file, 'extensions'), 'the config file');

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
        $presets = $this->strings($file, 'preset');
        $detected = $presets === [] ? $this->detected->preset() : '';

        if ($detected instanceof CannotJudge) {
            return $detected;
        }

        $layers = [];

        foreach ($detected === '' ? $presets : [$detected] as $preset) {
            $layers[] = $registry->preset(Name::of($preset));
        }

        return $this->validated([...$layers, $file, $this->document($given->layer())], $detected);
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
        $runner = array_key_exists('runner', $this->decoded($merged)) ? '' : $this->detected->runner();

        if ($runner instanceof CannotJudge) {
            return $runner;
        }

        $layer = $this->document($runner === '' ? $base : [...$base, 'runner' => $runner]);

        return $layer instanceof Document ? Layers::over($layer, $merged) : $layer;
    }

    /**
     * A setting that is a string or a list of them, as a config wrote it before it is validated.
     *
     * @return list<string>
     */
    private function strings(Document $document, string $key): array
    {
        $tree = $this->decoded($document);
        $value = array_key_exists($key, $tree) ? $tree[$key] : [];
        $strings = [];

        foreach (is_array($value) ? $value : [$value] as $entry) {
            if (is_string($entry)) {
                $strings[] = $entry;
            }
        }

        return $strings;
    }

    /** @return array<mixed> */
    private function decoded(Document $document): array
    {
        $tree = Json::decode($document->json());

        return is_array($tree) ? $tree : [];
    }

    /** @param array<mixed> $tree */
    private function document(array $tree): Document|CannotJudge
    {
        return Document::ofJson(Json::encode(Json::object($tree)));
    }
}
