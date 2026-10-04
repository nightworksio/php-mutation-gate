<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use function array_map;

use NightWorksIO\MutationGate\Core\Config\BuiltinPreset;
use NightWorksIO\MutationGate\Core\Config\Floors;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Mutators;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\PresetSet;
use NightWorksIO\MutationGate\Core\Config\Reach;
use NightWorksIO\MutationGate\Core\Config\Setup;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\Registry\FirstPartyPackage;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Extension\Extensions;

/**
 * The presets this package ships (ADR-0008): layers of config laid before
 * the config file, so the project's own settings win. Paths every framework
 * test runs through, such as a Laravel app's `app/Providers` and a Symfony
 * app's `src/Kernel.php`, stay in their trees, where the hot-path warning
 * finds them.
 */
final readonly class Presets
{
    /**
     * Each preset: the trees where `phpunit.xml` has no `<source>`, which for a plain library are the `autoload`
     * paths of `composer.json`; the files every test reads; the seconds a mutant may run, longer where its
     * tests boot a framework; and the mutator sets it offers, each with the package that registers it
     * (ADR-0021). Each also holds new code to every mutant killed. A preset sets every value
     * ADR-0008's table gives it, so a later preset in a list replaces each of an earlier one's.
     */
    private const array SHIPPED = [
        BuiltinPreset::Library->value => [
            'fallback' => [],
            'everything' => [],
            'seconds' => 10,
            'sets' => ['security' => FirstPartyPackage::SecuritySet->value],
        ],
        BuiltinPreset::Laravel->value => [
            'fallback' => ['app'],
            'everything' => ['bootstrap/**', 'config/**', 'routes/**', '.env.testing'],
            'seconds' => 30,
            'sets' => [
                'laravel' => FirstPartyPackage::LaravelSet->value,
                'security' => FirstPartyPackage::SecuritySet->value,
            ],
        ],
        BuiltinPreset::Symfony->value => [
            'fallback' => ['src'],
            'everything' => ['config/**', '.env.test', 'tests/bootstrap.php'],
            'seconds' => 30,
            'sets' => [
                'symfony' => FirstPartyPackage::SymfonySet->value,
                'security' => FirstPartyPackage::SecuritySet->value,
            ],
        ],
    ];

    /** The registry with every shipped preset registered. */
    public static function registered(Extensions $registry): Extensions
    {
        $presets = $registry;

        foreach (self::SHIPPED as $name => $preset) {
            $presets = $presets->withPreset(
                Name::of($name),
                Layer::of(
                    Setup::of(
                        treeSource: Setup::phpunit(...$preset['fallback']),
                        mutators: self::offered(Name::of($name), $preset['sets']),
                    ),
                    Floors::of(newCode: Floor::whole()),
                    Reach::of(everything: Listed::of(...array_map(Glob::of(...), $preset['everything']))),
                    Triage::of(limit: Seconds::of($preset['seconds'])),
                ),
            );
        }

        return $presets;
    }

    /**
     * The mutator sets a preset offers.
     *
     * @param array<string, string> $sets each set's package, by the set's name
     */
    private static function offered(Name $preset, array $sets): Mutators
    {
        $offered = [];

        foreach ($sets as $set => $package) {
            $offered[] = PresetSet::of(Name::of($set), $preset, $package);
        }

        return Mutators::offered(...$offered);
    }
}
