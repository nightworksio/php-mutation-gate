<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Testo;

use function file_put_contents;
use function is_file;
use function is_string;
use function sprintf;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;

use function sys_get_temp_dir;
use function tempnam;

use Testo\Bridge\Symfony\Console\Command\Run;
use Testo\Common\Info;

use function unlink;
use function var_export;

/**
 * Testo as the gate's launcher starts it, in place of Testo's own script:
 * Testo's console and its `run`, with Testo's own arguments and none of the
 * gate's, reading the gate's config (see GateConfig), which loads the
 * project's own `testo.php` and adds the gate's plugin, told what the gate
 * told the launcher. Testo includes its config from a file on disk by its
 * path, which no file inside the gate's PHAR is, so the config is written
 * to a file of its own for each run.
 */
final readonly class Launch
{
    private const string RUN = 'run';

    private const string GIVEN = '--config=%s';

    /** The prefix of the file the config is written to for one run, in the system's temporary directory. */
    private const string WRITTEN = 'mutation-gate-testo-';

    /** The config's code: the gate's config, told the launcher's arguments. */
    private const string CONFIG = "<?php\n\nreturn \\%s::told(%s);\n";

    /** @param list<string> $arguments */
    private function __construct(private array $arguments, private Told $told)
    {
    }

    /** @param list<string> $arguments the launcher's command line after the project's autoloader */
    public static function of(array $arguments): self
    {
        return new self($arguments, Told::from($arguments));
    }

    /** The gate's config for this run, written to a file of its own, by its path. */
    public function written(): string
    {
        $config = tempnam(sys_get_temp_dir(), self::WRITTEN);
        $written = is_string($config) && file_put_contents($config, $this->config()) !== false;

        return is_string($config) && $written ? $config : throw UnwrittenConfig::in(sys_get_temp_dir());
    }

    /** Deletes the config written for a run, once the run has ended. */
    public static function cleared(string $config): void
    {
        if (is_file($config)) {
            unlink($config);
        }
    }

    /** The code of the gate's config for this run. */
    public function config(): string
    {
        return sprintf(self::CONFIG, GateConfig::class, var_export($this->arguments, return: true));
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

    /** Testo's `run`, reading the config in this file, with Testo's own arguments, behind the launcher's script. */
    public function input(string $script, string $config): ArgvInput
    {
        return new ArgvInput([
            $script,
            self::RUN,
            sprintf(self::GIVEN, $config),
            ...$this->told->arguments(),
        ]);
    }
}
