<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Testo;

use function file_put_contents;
use function getenv;
use function ini_get;

use Internal\Container\Container;
use NightWorksIO\MutationGate\Core\Native\Guard;
use NightWorksIO\MutationGate\Core\Native\Variable;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Opcache;
use Testo\Common\EventListenerCollector;
use Testo\Common\PluginConfigurator;
use Testo\Event\Framework\SessionFinished;
use Testo\Pipeline\InterceptorCollector;

/**
 * The gate's Testo plugin, which its launcher's config adds to the project's
 * own testo.php (ADR-0027): it runs only the tests the run selects, records
 * each to the file `MUTATION_GATE_RESULTS` names, where it names one, stops
 * after the first that fails where the run stops, times each where it is
 * told a file to, and lists them where it is told to; and it says in the
 * file `MUTATION_GATE_GUARD` names whether opcache could serve a cached
 * original in place of the mutated file.
 */
final readonly class GatePlugin implements PluginConfigurator
{
    public function __construct(private Told $told)
    {
    }

    /** Testo hands its container, typed as the object it is; the plugin takes from it only what it configures. */
    public function configure(object $container): void
    {
        if (! $container instanceof Container) {
            return;
        }

        $listed = $this->told->listing();
        $listing = $listed instanceof NotGiven ? $listed : new Listing($listed);
        $results = getenv(Variable::Results->value);

        $container->get(InterceptorCollector::class)->addInterceptor(new Running(
            $this->told->selected(),
            $this->told->stops(),
            $results === false || $results === '' ? NotGiven::value() : $results,
            $this->told->times(),
            $listing,
        ));

        if ($listing instanceof Listing) {
            $container->get(EventListenerCollector::class)
                ->addListener(SessionFinished::class, static function () use ($listing): void {
                    $listing->written();
                });
        }

        self::noting(getenv(Variable::Guard->value), Opcache::ofCommandLine(ini_get(Opcache::CLI)));
    }

    /** Says in the guard file, where one is named, whether opcache could serve a cached original. */
    public static function noting(string|false $file, Opcache $opcache): void
    {
        if ($file !== false && $file !== '') {
            file_put_contents($file, Guard::noting($opcache), FILE_APPEND | LOCK_EX);
        }
    }
}
