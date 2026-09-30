<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_flip;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function count;

use NightWorksIO\MutationGate\Core\Config\Definition\Adapter;
use NightWorksIO\MutationGate\Core\Config\Definition\Presets;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Runner\Withheld;

use function sprintf;

/**
 * What a config builds on (ADR-0002, ADR-0008): the extensions it loads, the
 * presets it applies, the runner, what the runner withholds from the
 * project's tests, and where the trees come from.
 */
final readonly class Setup implements Part
{
    /** The tree source when no layer names one. */
    private const string TREE_SOURCE = 'phpunit';

    /** The runners and presets the builder has a method of its own for. */
    private const array RUNNERS = ['pest', 'infection'];

    private const array PRESETS = ['library', 'laravel', 'symfony'];

    /**
     * @param Listed<string>|Absent $extensions
     * @param Listed<string>|Absent $presets
     * @param list<string>          $withhold
     */
    private function __construct(
        private Listed|Absent $extensions,
        private Listed|Absent $presets,
        private Choice|Absent $runner,
        private array $withhold,
        private Choice|Absent $treeSource,
    ) {
    }

    /**
     * @param Listed<string>|Absent $extensions
     * @param Listed<string>|Absent $presets
     */
    public static function of(
        Listed|Absent $extensions = new Absent(),
        Listed|Absent $presets = new Absent(),
        Choice|Absent $runner = new Absent(),
        Withheld|Absent $withhold = new Absent(),
        Choice|Absent $treeSource = new Absent(),
    ): self {
        return new self(
            $extensions,
            $presets,
            $runner,
            $withhold instanceof Withheld ? [...$withhold] : [],
            $treeSource,
        );
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        $none = self::none();

        return self::of(extensions: $none->extensions(), treeSource: $none->treeSource());
    }

    /** Presets and extensions add to an earlier layer's; a runner or a tree source is chosen whole. */
    public function over(Part $later): self
    {
        return $later instanceof self
            ? new self(
                self::joined($this->extensions, $later->extensions),
                self::joined($this->presets, $later->presets),
                Absent::laid($this->runner, $later->runner),
                array_values(array_unique([...$this->withhold, ...$later->withhold])),
                Absent::laid($this->treeSource, $later->treeSource),
            )
            : $this;
    }

    /** @return Listed<string> the extension classes the config loads, beside those Composer names */
    public function extensions(): Listed
    {
        return $this->extensions instanceof Listed ? $this->extensions : Listed::of();
    }

    /** @return Listed<string>|Absent the presets a layer names, in order, or none, for zero-config to choose */
    public function presets(): Listed|Absent
    {
        return $this->presets;
    }

    /** The runner a layer chooses, or none, for a later layer or zero-config to choose. */
    public function runner(): Choice|Absent
    {
        return $this->runner;
    }

    /** `runner.withhold`: what the runner withholds from the project's tests, beside what every run does. */
    public function withhold(): Withheld
    {
        return Withheld::of(...$this->withhold);
    }

    public function treeSource(): Choice
    {
        return $this->treeSource instanceof Choice ? $this->treeSource : self::phpunit();
    }

    /** The `phpunit` tree source, whose trees are these paths where `phpunit.xml` has no `<source>`. */
    public static function phpunit(string ...$fallback): Choice
    {
        return Choice::of(
            self::TREE_SOURCE,
            Json::object(Member::of('fallback', Json::items(...array_values($fallback)))),
        );
    }

    public function written(Origin $origin): Json
    {
        $written = $this->extensions instanceof Listed
            ? Json::object(Member::of('extensions', Json::items(...$this->extensions)))
            : Json::object();
        $written = $this->presets instanceof Listed
            ? $written->with(Member::of('preset', self::presetsWritten($this->presets)))
            : $written;
        $written = $this->runnerWritten($written);

        return $this->treeSource instanceof Choice
            ? $written->with(Member::of('treeSource', $this->treeSource->written()))
            : $written;
    }

    public function php(Origin $origin): PhpCalls
    {
        $calls = $this->extensions instanceof Listed && [...$this->extensions] !== []
            ? PhpCalls::onGate(
                'extensions',
                ...array_map(
                    static fn(string $class): string => sprintf('Load::extension(%s)', PhpCalls::literal($class)),
                    [...$this->extensions],
                ),
            )
            : PhpCalls::none();
        $calls = $this->presets instanceof Listed && [...$this->presets] !== []
            ? $calls->and(PhpCalls::onGate('preset', ...array_map(self::preset(...), [...$this->presets])))
            : $calls;
        $calls = $calls->and($this->runnerPhp());

        return $this->treeSource instanceof Choice
            ? $calls->and(PhpCalls::onGate('treeSource', self::source($this->treeSource)))
            : $calls;
    }

    /** The runner, by itself as the adapter it chooses, or as an object with what it withholds. */
    private function runnerWritten(Json $written): Json
    {
        $chosen = $this->runner instanceof Choice ? $this->runner->written() : Json::object();

        if ($this->withhold === []) {
            return $this->runner instanceof Choice ? $written->with(Member::of('runner', $chosen)) : $written;
        }

        $runner = $chosen instanceof Json ? $chosen : Json::object(Member::of('use', $chosen));

        return $written->with(
            Member::of('runner', $runner->with(Member::of('withhold', Json::items(...$this->withhold)))),
        );
    }

    /** The runner as the builder chooses it, with `->withholding()` where it withholds anything. */
    private function runnerPhp(): PhpCalls
    {
        $withheld = sprintf('Withheld::of(%s)', PhpCalls::literals(...$this->withhold));

        return match (true) {
            $this->runner instanceof Choice && $this->withhold === [] => PhpCalls::onGate(
                'runner',
                PhpCalls::chosen($this->runner, 'Runner', ...self::RUNNERS),
            ),
            $this->runner instanceof Choice => PhpCalls::onGate(
                'runner',
                sprintf('%s->withholding(%s)', PhpCalls::chosen($this->runner, 'Runner', ...self::RUNNERS), $withheld),
            ),
            $this->withhold === [] => PhpCalls::none(),
            default => PhpCalls::onGate('withholding', $withheld),
        };
    }

    /** @param Listed<string> $presets */
    private static function presetsWritten(Listed $presets): Json|string
    {
        $names = [...$presets];

        return count($names) === 1 ? $names[0] : Json::items(...$names);
    }

    private static function preset(string $name): string
    {
        return array_key_exists($name, array_flip(self::PRESETS))
            ? sprintf('Preset::%s()', $name)
            : sprintf('Preset::named(%s)', PhpCalls::literal($name));
    }

    /** The tree source, by `Source::phpunit()` with its fallback paths where it is that one. */
    private static function source(Choice $source): string
    {
        $options = Node::config($source->options()->line());
        $fallback = $options->field('fallback');
        $paths = $fallback->kind() === Kind::List ? $fallback->items() : [];
        $onlyFallback = array_keys($options->kind() === Kind::Map ? $options->entries() : []) === ['fallback'];

        return $source->use() === self::TREE_SOURCE && ($onlyFallback || $source->options()->isEmpty())
            ? sprintf(
                'Source::phpunit(%s)',
                PhpCalls::literals(...array_map(
                    static fn(Node $path): string => $path->text(),
                    $paths,
                )),
            )
            : PhpCalls::chosen($source, 'Source', 'composer');
    }

    /**
     * @param  Listed<string>|Absent $earlier
     * @param  Listed<string>|Absent $later
     * @return Listed<string>|Absent
     */
    private static function joined(Listed|Absent $earlier, Listed|Absent $later): Listed|Absent
    {
        return match (true) {
            $later instanceof Absent => $earlier,
            $earlier instanceof Absent => $later,
            default => $earlier->and($later, static fn(string $name): string => $name),
        };
    }
}
