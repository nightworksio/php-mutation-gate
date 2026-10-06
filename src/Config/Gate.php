<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use Closure;

use function count;

use NightWorksIO\MutationGate\Core\Config\Definition;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\PathOrigin;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Workers;

/**
 * The PHP config (ADR-0002): a `mutation-gate.php` returns
 * `Gate::configure()` with its settings. Each method writes its part of the
 * config as a JSON file would, and the config is read through the same
 * definition as every other format.
 */
final readonly class Gate
{
    private function __construct(private Json $config)
    {
    }

    public static function configure(): self
    {
        return new self(Json::object());
    }

    /** `extensions`: extension classes to load beside those Composer names. */
    public function extensions(Load ...$extensions): self
    {
        return $this->set('extensions', $this->each($extensions, static fn(Load $load): string => $load->class()));
    }

    /** `preset`: one preset, or several applied in order. */
    public function preset(Preset $preset, Preset ...$more): self
    {
        $names = $this->each([$preset, ...$more], static fn(Preset $one): string => $one->name());

        return $this->set('preset', count($more) === 0 ? $preset->name() : $names);
    }

    public function runner(Runner $runner): self
    {
        return $this->set('runner', $runner->written());
    }

    /** `runner.withhold`, where a preset or another layer chooses the runner: `Withheld::of('DEPLOY_*')`. */
    public function withholding(Withheld $withheld): self
    {
        $withhold = Json::object(Member::of('withhold', Json::items(...$withheld)));

        return $this->merge(Json::object(Member::of('runner', $withhold)));
    }

    /**
     * `runner.memory`, where a preset or another layer chooses the runner:
     * `MemoryCap::of(512, MemoryUnit::Megabytes)`.
     */
    public function cappedAt(MemoryCap $memory): self
    {
        return $this->merge(Json::object(Member::of('runner', Json::object(Member::of('memory', $memory->written())))));
    }

    /** `runner.workers`, where a preset or another layer chooses the runner: `Workers::Fresh`. */
    public function inWorkers(Workers $workers): self
    {
        return $this->merge(Json::object(Member::of('runner', Json::object(Member::of('workers', $workers->value)))));
    }

    public function treeSource(Source $source): self
    {
        return $this->set('treeSource', $source->written());
    }

    /** `trees`: every tree, in place of those the tree source would find. */
    public function trees(Tree ...$trees): self
    {
        return $this->set('trees', $this->each($trees, static fn(Tree $tree): Json => $tree->written()));
    }

    /** `newCode.floor` */
    public function newCode(Floor $floor): self
    {
        return $this->merge(Json::object(Member::of('newCode', Json::object(Member::of('floor', $floor->percent())))));
    }

    /** `security.floor` */
    public function security(Floor $floor): self
    {
        return $this->merge(Json::object(Member::of('security', Json::object(Member::of('floor', $floor->percent())))));
    }

    /** `ignores.entries`, added to those already given. */
    public function ignoring(Ignore ...$ignores): self
    {
        $entries = $this->each($ignores, static fn(Ignore $ignore): Json => $ignore->written());

        return $this->merge(Json::object(Member::of('ignores', Json::object(Member::of('entries', $entries)))));
    }

    /** `reports` */
    public function reporting(Report ...$reports): self
    {
        return $this->set('reports', $this->each($reports, static fn(Report $report): Json => $report->written()));
    }

    /** Any other setting, such as `Shards::seconds(900)` or `Timeouts::unjudged()`, laid over those already given. */
    public function with(Setting ...$settings): self
    {
        $gate = $this;

        foreach ($settings as $setting) {
            $gate = $gate->merge($setting->written());
        }

        return $gate;
    }

    /** The config this writes, as a JSON file would write it. */
    public function written(): Json
    {
        return $this->config;
    }

    /** The layer of config this writes, read as every other format is, its paths named from this origin. */
    public function layer(PathOrigin $origin): Layer|Invalid
    {
        return Definition::layer(Node::config($this->config->line()), $origin);
    }

    private function set(string $key, Json|string $value): self
    {
        return new self($this->config->with(Member::of($key, $value)));
    }

    private function merge(Json $part): self
    {
        return new self($this->config->merged($part));
    }

    /**
     * @template T
     *
     * @param  array<T>                     $values
     * @param  Closure(T): (Json|string)   $json
     */
    private function each(array $values, Closure $json): Json
    {
        $each = [];

        foreach ($values as $value) {
            $each[] = $json($value);
        }

        return Json::items(...$each);
    }
}
