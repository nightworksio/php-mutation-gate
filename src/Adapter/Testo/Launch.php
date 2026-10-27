<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Testo;

use NightWorksIO\MutationGate\Core\ThisPackage;

use function sprintf;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Testo\Bridge\Symfony\Console\Command\Run;
use Testo\Common\Info;

/**
 * Testo as the gate's launcher starts it, in place of Testo's own script:
 * Testo's console and its `run`, reading the gate's config, which loads the
 * project's own `testo.php` and adds the gate's plugin, with Testo's own
 * arguments and none of the gate's. The launcher's command line stays as it
 * was started, and the gate's config reads what the gate told it from there.
 */
final readonly class Launch
{
    /** The gate's config, in this package's own directory, which Testo loads in place of the project's. */
    private const string CONFIG = 'mutation-gate-testo-config.php';

    private const string RUN = 'run';

    private const string GIVEN = '--config=%s';

    private function __construct(private Told $told)
    {
    }

    /** @param list<string> $arguments the launcher's command line after the project's autoloader */
    public static function of(array $arguments): self
    {
        return new self(Told::from($arguments));
    }

    /** Testo's console, as Testo's own script builds it, with its `run` alone. */
    public function testo(): Application
    {
        $testo = new Application(Info::NAME, Info::version());
        $testo->addCommand(new Run());
        $testo->setDefaultCommand(self::RUN);
        $testo->setAutoExit(boolean: false);

        return $testo;
    }

    /** Testo's `run`, reading the gate's config, with Testo's own arguments, behind the launcher's script. */
    public function input(string $script): ArgvInput
    {
        return new ArgvInput([
            $script,
            self::RUN,
            sprintf(self::GIVEN, ThisPackage::script(self::CONFIG)),
            ...$this->told->arguments(),
        ]);
    }
}
