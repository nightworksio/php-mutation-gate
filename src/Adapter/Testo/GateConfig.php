<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Testo;

use function array_slice;
use function getcwd;
use function is_file;

use NightWorksIO\MutationGate\Core\Runner\TestoConfig;

use function sprintf;

use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\Plugin\PluginCollection;

/**
 * The config Testo reads in a run the gate's launcher started: the
 * project's own `testo.php`, loaded as it is, or Testo's default where there
 * is none, with the gate's plugin added to the plugins it loads for every
 * suite. What the gate told the launcher is on the launcher's own command
 * line, which Testo never reads.
 */
final readonly class GateConfig
{
    /**
     * The project's config with the gate's plugin, told what is on the
     * launcher's command line after its script and the project's autoloader.
     *
     * @param list<string> $arguments
     */
    public static function ofLauncher(array $arguments): ApplicationConfig
    {
        return self::wrapping(self::project(), Told::from(array_slice($arguments, 2)));
    }

    /** This config with the gate's plugin added to the plugins it loads for every suite. */
    public static function wrapping(ApplicationConfig $project, Told $told): ApplicationConfig
    {
        $plugins = $project->plugins;
        $gate = new GatePlugin($told);

        return new ApplicationConfig(
            src: $project->src,
            suites: $project->suites,
            plugins: $plugins instanceof PluginCollection ? $plugins->with($gate) : [...$plugins, $gate],
        );
    }

    /** The project's own config, in the directory Testo runs in, or Testo's default where there is none. */
    private static function project(): ApplicationConfig
    {
        $file = sprintf('%s/%s', (string) getcwd(), TestoConfig::Php->value);
        $config = is_file($file) ? (static fn(string $file): mixed => require $file)($file) : new ApplicationConfig();

        return $config instanceof ApplicationConfig
            ? $config
            : throw NotATestoConfig::at($file);
    }
}
