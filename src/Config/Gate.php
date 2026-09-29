<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use Closure;

use function count;
use function is_array;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Definition\Json;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\Config\Layers;

/**
 * The PHP config (ADR-0002): a `mutation-gate.php` returns
 * `Gate::configure()` with its settings. Each method writes the part of the
 * same untyped config every other format reads into, and one validator reads
 * them all.
 */
final readonly class Gate
{
    /** @param array<mixed> $config */
    private function __construct(private array $config)
    {
    }

    public static function configure(): self
    {
        return new self([]);
    }

    /** `extensions`: extension classes to load beside those Composer names. */
    public function extensions(Load ...$extensions): self
    {
        return $this->set('extensions', self::each($extensions, static fn(Load $load): string => $load->class()));
    }

    /** `preset`: one preset, or several applied in order. */
    public function preset(Preset $preset, Preset ...$more): self
    {
        $names = self::each([$preset, ...$more], static fn(Preset $one): string => $one->name());

        return $this->set('preset', count($names) === 1 ? $preset->name() : $names);
    }

    public function runner(Runner $runner): self
    {
        return $this->set('runner', Json::decode($runner->written()));
    }

    public function treeSource(Source $source): self
    {
        return $this->set('treeSource', Json::decode($source->written()));
    }

    /** `trees`: every tree, in place of those the tree source would find. */
    public function trees(Tree ...$trees): self
    {
        return $this->set('trees', self::each($trees, static fn(Tree $tree): mixed => Json::decode($tree->written())));
    }

    /** `newCode.floor` */
    public function newCode(Floor $floor): self
    {
        return $this->merge(['newCode' => ['floor' => $floor->percent()]]);
    }

    /** `ignores.entries`, added to those already given. */
    public function ignoring(Ignore ...$ignores): self
    {
        $entries = self::each($ignores, static fn(Ignore $ignore): mixed => Json::decode($ignore->written()));

        return $this->merge(['ignores' => ['entries' => $entries]]);
    }

    /** `reports` */
    public function reporting(Report ...$reports): self
    {
        return $this->set(
            'reports',
            self::each($reports, static fn(Report $report): mixed => Json::decode($report->written())),
        );
    }

    /** Any other setting, such as `Shards::seconds(900)` or `Timeouts::unjudged()`, laid over those already given. */
    public function with(Setting ...$settings): self
    {
        $gate = $this;

        foreach ($settings as $setting) {
            $gate = $gate->merge(Json::decode($setting->written()));
        }

        return $gate;
    }

    /** The config this writes, read as every other format is. */
    public function document(): Document|CannotJudge
    {
        return Document::ofJson(Json::encode(Json::object($this->config)));
    }

    private function set(string $key, mixed $value): self
    {
        return new self([...$this->config, $key => $value]);
    }

    private function merge(mixed $part): self
    {
        $merged = Layers::merged($this->config, $part);

        return new self(is_array($merged) ? $merged : $this->config);
    }

    /**
     * @template T
     *
     * @param  array<T>          $values
     * @param  Closure(T): mixed $json
     * @return list<mixed>
     */
    private static function each(array $values, Closure $json): array
    {
        $each = [];

        foreach ($values as $value) {
            $each[] = $json($value);
        }

        return $each;
    }
}
