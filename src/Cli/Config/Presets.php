<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Reach;
use NightWorksIO\MutationGate\Core\Config\Setup;
use NightWorksIO\MutationGate\Core\Config\Triage;
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
     * paths of `composer.json`; the files every test reads; and, where its tests boot a framework, the seconds a
     * mutant may run.
     */
    private const array SHIPPED = [
        'library' => ['fallback' => [], 'everything' => []],
        'laravel' => [
            'fallback' => ['app'],
            'everything' => ['bootstrap/**', 'config/**', 'routes/**', '.env.testing'],
            'seconds' => 30,
        ],
        'symfony' => [
            'fallback' => ['src'],
            'everything' => ['config/**', '.env.test', 'tests/bootstrap.php'],
            'seconds' => 30,
        ],
    ];

    /** The registry with every shipped preset registered. */
    public static function registered(Extensions $registry): Extensions
    {
        $presets = $registry;

        foreach (self::SHIPPED as $name => $preset) {
            $layer = Layer::of(
                Setup::of(treeSource: Setup::phpunit(...$preset['fallback'])),
                Reach::of(everything: Listed::of(...$preset['everything'])),
            );
            $presets = $presets->withPreset(
                Name::of($name),
                array_key_exists('seconds', $preset)
                    ? $layer->over(Layer::of(Triage::of(limit: Seconds::of($preset['seconds']))))
                    : $layer,
            );
        }

        return $presets;
    }
}
