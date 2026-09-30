<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use NightWorksIO\MutationGate\Config\Floor;
use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Config\Reach;
use NightWorksIO\MutationGate\Config\Source;
use NightWorksIO\MutationGate\Config\Timeouts;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Document;

/**
 * The presets this package ships (ADR-0008), written with the PHP builder:
 * config fragments applied before the config file, so the project's own
 * settings win. Paths every framework test runs through, such as a Laravel
 * app's `app/Providers` and a Symfony app's `src/Kernel.php`, stay in their
 * trees, where the hot-path warning finds them.
 */
final readonly class Presets
{
    /** New code is held to every mutant killed. */
    private const int NEW_CODE_FLOOR = 100;

    /** The seconds a library's mutant may run: its tests boot nothing. */
    private const int LIBRARY_TIMEOUT = 10;

    /** The seconds an application's mutant may run: its tests boot the framework. */
    private const int APPLICATION_TIMEOUT = 30;

    /** A plain library: its trees are the `autoload` paths of `composer.json` when `phpunit.xml` has no `<source>`. */
    public static function library(): Document|CannotJudge
    {
        return Gate::configure()
            ->treeSource(Source::phpunit())
            ->newCode(Floor::of(self::NEW_CODE_FLOOR))
            ->with(Timeouts::seconds(self::LIBRARY_TIMEOUT))
            ->document();
    }

    /** A Laravel app, whose tests boot the application. */
    public static function laravel(): Document|CannotJudge
    {
        return Gate::configure()
            ->treeSource(Source::phpunit('app'))
            ->newCode(Floor::of(self::NEW_CODE_FLOOR))
            ->with(
                Reach::everything('bootstrap/**', 'config/**', 'routes/**', '.env.testing'),
                Timeouts::seconds(self::APPLICATION_TIMEOUT),
            )
            ->document();
    }

    /** A Symfony app, whose tests boot the kernel. */
    public static function symfony(): Document|CannotJudge
    {
        return Gate::configure()
            ->treeSource(Source::phpunit('src'))
            ->newCode(Floor::of(self::NEW_CODE_FLOOR))
            ->with(
                Reach::everything('config/**', '.env.test', 'tests/bootstrap.php'),
                Timeouts::seconds(self::APPLICATION_TIMEOUT),
            )
            ->document();
    }
}
