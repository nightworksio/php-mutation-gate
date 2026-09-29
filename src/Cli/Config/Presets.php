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
    /** A plain library: its trees are the `autoload` paths of `composer.json` when `phpunit.xml` has no `<source>`. */
    public static function library(): Document|CannotJudge
    {
        return Gate::configure()
            ->treeSource(Source::phpunit())
            ->newCode(Floor::of(100))
            ->with(Timeouts::seconds(10))
            ->document();
    }

    /** A Laravel app, whose tests boot the application. */
    public static function laravel(): Document|CannotJudge
    {
        return Gate::configure()
            ->treeSource(Source::phpunit('app'))
            ->newCode(Floor::of(100))
            ->with(Reach::everything('bootstrap/**', 'config/**', 'routes/**', '.env.testing'), Timeouts::seconds(30))
            ->document();
    }

    /** A Symfony app, whose tests boot the kernel. */
    public static function symfony(): Document|CannotJudge
    {
        return Gate::configure()
            ->treeSource(Source::phpunit('src'))
            ->newCode(Floor::of(100))
            ->with(Reach::everything('config/**', '.env.test', 'tests/bootstrap.php'), Timeouts::seconds(30))
            ->document();
    }
}
