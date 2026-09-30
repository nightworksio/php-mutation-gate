<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Runtime;

use function array_key_exists;
use function array_keys;
use function array_values;
use function explode;
use function implode;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Doctor\Check\Xdebug;
use NightWorksIO\MutationGate\Core\Doctor\RunnerPhp;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function preg_match;
use function sprintf;
use function str_starts_with;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

use function trim;

/**
 * The PHP a runner starts, described by `php -m` and `php -i` with the
 * runner's own options. Neither runs the project's code, and neither sees a
 * variable withheld.
 */
final readonly class PhpProbe
{
    /** How long each description may take, in seconds. */
    private const float LIMIT = 30.0;

    /** A setting of `php -i`: its name, its value here, and, after it, the value of the php.ini. */
    private const string SETTING = '/^(?<name>[^=]+?) => (?<value>.*?)(?: => .*)?$/D';

    /** What `php -i` shows for a setting with no value. */
    private const string NO_VALUE = 'no value';

    private const string EXTENSION_DIRECTORY = 'extension_dir';

    /** The coverage drivers an extension directory can hold, by the file each is installed as. */
    private const array DRIVERS = ['pcov' => 'pcov.so', 'xdebug' => 'xdebug.so'];

    private const string FAILED = '%s %s could not describe itself: %s';

    /**
     * @param array<string, string> $environment the environment the gate runs in
     * @param Seconds               $limit       how long each description may take
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
    public function describe(Withheld $withheld, string ...$options): RunnerPhp|CannotJudge
    {
        $modules = $this->ran($withheld, [...array_values($options), '-m']);
        $info = $modules instanceof CannotJudge ? $modules : $this->ran($withheld, [...array_values($options), '-i']);

        return match (true) {
            $modules instanceof CannotJudge => $modules,
            $info instanceof CannotJudge => $info,
            default => $this->read($modules, $info, $withheld),
        };
    }

    /**
     * @param list<string> $arguments
     */
    private function ran(Withheld $withheld, array $arguments): string|CannotJudge
    {
        $environment = [];

        foreach (array_keys($this->environment) as $name) {
            $environment += preg_match($withheld->pattern(), $name) === 1 ? [$name => false] : [];
        }

        $process = new Process([$this->binary, ...$arguments], null, $environment, null, $this->limit->seconds());

        try {
            $process->run();
        } catch (ExceptionInterface $failure) {
            return $this->failed($arguments, $failure->getMessage());
        }

        return $process->isSuccessful()
            ? $process->getOutput()
            : $this->failed($arguments, trim(sprintf('%s%s', $process->getOutput(), $process->getErrorOutput())));
    }

    /** @param list<string> $arguments */
    private function failed(array $arguments, string $said): CannotJudge
    {
        return CannotJudge::because(sprintf(self::FAILED, $this->binary, implode(' ', $arguments), $said));
    }

    private function read(string $modules, string $info, Withheld $withheld): RunnerPhp
    {
        $php = RunnerPhp::at($this->binary);

        foreach (explode("\n", $modules) as $line) {
            $module = trim($line);
            $php = $module === '' || str_starts_with($module, '[') ? $php : $php->loading($module);
        }

        foreach (explode("\n", $info) as $line) {
            $php = $this->set($php, trim($line));
        }

        return $this->offered($this->seen($php, $withheld));
    }

    /** The PHP, with the setting a line of `php -i` shows, where it shows one; `no value` is empty. */
    private function set(RunnerPhp $php, string $line): RunnerPhp
    {
        if (preg_match(self::SETTING, $line, $setting) !== 1) {
            return $php;
        }

        return $php->setting(trim($setting['name']), $setting['value'] === self::NO_VALUE ? '' : $setting['value']);
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
