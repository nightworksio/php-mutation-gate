<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use function array_map;
use function count;

use NightWorksIO\MutationGate\Cli\Registry\Lookup;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Definition;
use NightWorksIO\MutationGate\Core\Config\Definition\At;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Extension\Extensions;

use function sprintf;

/**
 * The layers of the presets a config names, in order (ADR-0002, decision 5),
 * and a problem at the path it names each at for each that nothing registers
 * or whose layer does not read as a config.
 */
final readonly class PresetLayers
{
    /**
     * @param list<Layer>   $layers
     * @param list<Problem> $problems
     */
    private function __construct(private array $layers, private array $problems)
    {
    }

    /** @param Listed<string>|Absent $named */
    public static function named(Listed|Absent $named, Extensions $registry): self
    {
        [$layers, $problems] = self::layered(self::paths($named), $registry);

        return new self($layers, $problems);
    }

    /** Every preset's layer, each laid over the one before it. */
    public function laid(): Layer
    {
        $laid = Layer::none();

        foreach ($this->layers as $layer) {
            $laid = $laid->over($layer);
        }

        return $laid;
    }

    /** @return list<Problem> */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * The presets a layer names, by the path it names each at: `preset` for one, `preset[1]` in a list.
     *
     * @param  Listed<string>|Absent $named
     * @return array<string, string>
     */
    private static function paths(Listed|Absent $named): array
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
    private static function layered(array $presets, Extensions $registry): array
    {
        $layers = [];
        $problems = [];

        foreach ($presets as $path => $preset) {
            $layer = Lookup::in($registry)->preset(Name::of($preset));
            $judged = $layer instanceof Layer ? self::judged($layer) : $layer;

            if ($judged instanceof Layer) {
                $layers[] = $judged;

                continue;
            }

            $problems = [...$problems, ...$judged instanceof Invalid
                ? array_map(
                    static fn(Problem $problem): Problem => Problem::at(
                        $path,
                        sprintf('%s sets %s: %s', $preset, $problem->path(), $problem->message()),
                    ),
                    [...$judged],
                )
                : [Problem::at($path, $judged->why())]];
        }

        return [$layers, $problems];
    }

    /**
     * A preset's layer as it was built, where what it writes reads back as a
     * config, so the mutator sets it offers stay offered (ADR-0021, decision
     * 12); every problem with it otherwise.
     */
    private static function judged(Layer $preset): Layer|Invalid
    {
        $judged = Definition::judged($preset, ProjectRoot::origin());

        return $judged instanceof Layer ? $preset : $judged;
    }
}
