<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Runtime;

use function array_key_exists;
use function array_keys;
use function array_values;
use function implode;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Doctor\Check\Xdebug;
use NightWorksIO\MutationGate\Core\Doctor\RunnerPhp;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\Runner\Platform;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Withholding;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function preg_match;
use function sprintf;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

use function trim;

/**
 * The PHP a runner starts, as it describes itself when started with the
 * runner's own options (see Platform). Describing runs none of the project's
 * code, and never sees a variable withheld.
 */
final readonly class PhpProbe
{
    /** How long a description may take, in seconds. */
    private const float LIMIT = 30.0;

    private const string EXTENSION_DIRECTORY = 'extension_dir';

    /** The coverage drivers an extension directory can hold, by the file each is installed as. */
    private const array DRIVERS = ['pcov' => 'pcov.so', 'xdebug' => 'xdebug.so'];

    private const string FAILED = '%s %s could not describe itself: %s';

    /**
     * @param array<string, string> $environment the environment the gate runs in
     * @param Seconds               $limit       how long a description may take
     */
    public function __construct(private string $binary, private array $environment, private Seconds $limit)
    {
    }

    /**
     * The PHP at a binary, run in an environment, allowed the time a description takes.
     *
     * @param array<string, string> $environment
     */
    public static function of(string $binary, array $environment): self
    {
        return new self($binary, $environment, Seconds::of(self::LIMIT));
    }

    /** The PHP, started with these options, or why it could not describe itself. */
    public function platform(Withheld $withheld, string ...$options): Platform|CannotJudge
    {
        $arguments = [...array_values($options), ...Platform::describing()];
        $environment = Withholding::of($withheld, $this->environment);
        $process = new Process([$this->binary, ...$arguments], null, $environment, null, $this->limit->seconds());

        try {
            $process->run();
        } catch (ExceptionInterface $failure) {
            return $this->failed($options, $failure->getMessage());
        }

        $output = sprintf('%s%s', $process->getOutput(), $process->getErrorOutput());
        $platform = $process->isSuccessful() ? Platform::describedBy($output) : CannotJudge::because(trim($output));

        return $platform instanceof CannotJudge ? $this->failed($options, $platform->why()) : $platform;
    }

    /**
     * The PHP, started with these options, as doctor reads it: what it loads,
     * the coverage drivers installed beside it, and the variables it sees,
     * or why it could not describe itself.
     */
    public function describe(Withheld $withheld, string ...$options): RunnerPhp|CannotJudge
    {
        $platform = $this->platform($withheld, ...$options);

        return $platform instanceof CannotJudge
            ? $platform
            : $this->offered($this->seen($this->read($platform), $withheld));
    }

    /** @param array<string> $options */
    private function failed(array $options, string $said): CannotJudge
    {
        $started = implode(' ', [...array_values($options), '-r']);

        return CannotJudge::because(sprintf(self::FAILED, $this->binary, $started, $said));
    }

    private function read(Platform $platform): RunnerPhp
    {
        $php = RunnerPhp::at($this->binary)->loading(...array_keys($platform->extensions()));
        $ini = $platform->iniFile();

        foreach ($platform->settings() as $name => $value) {
            $php = $php->setting($name, $value);
        }

        return is_string($ini) ? $php->loadingIni($ini) : $php;
    }

    /** The PHP, offering each coverage driver its extension directory holds. */
    private function offered(RunnerPhp $php): RunnerPhp
    {
        $directory = $php->valueOf(self::EXTENSION_DIRECTORY);

        foreach (self::DRIVERS as $driver => $file) {
            $php = is_string($directory) && is_file(DiskPath::of($directory)->child($file)->value())
                ? $php->offering($driver)
                : $php;
        }

        return $php;
    }

    /** The PHP, seeing Xdebug's mode where the environment it inherits sets it. */
    private function seen(RunnerPhp $php, Withheld $withheld): RunnerPhp
    {
        return array_key_exists(Xdebug::MODE_VARIABLE, $this->environment)
            && preg_match($withheld->pattern(), Xdebug::MODE_VARIABLE) !== 1
            ? $php->seeing(Xdebug::MODE_VARIABLE, $this->environment[Xdebug::MODE_VARIABLE])
            : $php;
    }
}
