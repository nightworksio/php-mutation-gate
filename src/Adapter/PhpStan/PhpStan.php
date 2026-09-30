<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpStan;

use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function is_string;
use function mkdir;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\BuiltinAnalyser;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Port\StaticChecker;

use const PHP_BINARY;

use function preg_match;
use function sprintf;

/**
 * PHPStan, asked about a mutant in the mode its authors built for editors
 * (ADR-0020, decisions 6 and 7): `--tmp-file` stands the mutant in for its
 * original. It reads a config of the gate's own, which includes the
 * project's, analyses in one process, and does not report an ignore that
 * matched nothing. The warm-up analyses every file the config names and
 * saves PHPStan's result cache, so each check analyses the mutant and the
 * files that depend on it alone, and saves nothing.
 */
final readonly class PhpStan implements StaticChecker
{
    private const string SCRIPT = 'vendor/bin/phpstan';

    private const string VERSION = '~PHPStan - PHP Static Analysis Tool (\S+)~';

    /** The config each run reads, beside the gate's other files. */
    private const string CHECK = '%s/phpstan/check.neon';

    private const string CHECK_CONFIG = <<<'NEON'
        includes:
            - %s
        parameters:
            parallel:
                maximumNumberOfProcesses: 1
            reportUnmatchedIgnoredErrors: false

        NEON;

    private const string UNVERSIONED = 'PHPStan did not say its version (%s).';

    private const string NO_CONFIG
        = 'PHPStan has no config to read: add a phpstan.neon, or name one in staticCheck.config.';

    private const string UNREAD = 'PHPStan\'s config %s cannot be read.';

    private const string UNWRITTEN = 'The gate cannot write PHPStan\'s config for its checks to %s.';

    private function __construct(private Root $root, private Path|Absent $config)
    {
    }

    /** PHPStan in the project's root, reading the config the gate hands it as `config`, or the one it finds. */
    public static function fromOptions(Options $options, string $root): self|Invalid
    {
        $config = $options->path(Key::of('config'));

        return $config instanceof Problem
            ? Invalid::because($config)
            : new self(Root::of($root), $config instanceof NotGiven ? Absent::setting() : $config);
    }

    public function identity(Withheld $withheld): AnalyserIdentity|CannotJudge
    {
        $config = $this->config();
        $version = Started::run($this->root, $withheld, [PHP_BINARY, self::SCRIPT, '--version']);
        $contents = $config instanceof Path && is_file($this->absolute($config))
            ? file_get_contents($this->absolute($config))
            : false;

        return match (true) {
            $config instanceof CannotJudge => $config,
            ! is_string($contents) => CannotJudge::because(sprintf(self::UNREAD, $config->value())),
            preg_match(self::VERSION, $version->output(), $said) !== 1 => CannotJudge::because(
                sprintf(self::UNVERSIONED, $version->said()),
            ),
            default => AnalyserIdentity::of(BuiltinAnalyser::PhpStan->value, $said[1], Digest::sha256Of($contents)),
        };
    }

    /** Every file the config names, analysed once, which saves the result cache each check starts from. */
    public function findings(Paths $files, Withheld $withheld): Findings|CannotJudge
    {
        return $this->analysed($withheld, []);
    }

    public function check(MutantCheck $check): Findings|CannotJudge
    {
        return $this->analysed($check->withheld(), [
            sprintf('--tmp-file=%s', $this->absolute($check->mutant())),
            sprintf('--instead-of=%s', $this->absolute($check->original())),
        ]);
    }

    /** @param list<string> $editing */
    private function analysed(Withheld $withheld, array $editing): Findings|CannotJudge
    {
        $check = $this->checkConfig();

        return $check instanceof CannotJudge ? $check : Report::of(Started::run($this->root, $withheld, [
            PHP_BINARY,
            self::SCRIPT,
            'analyse',
            sprintf('--configuration=%s', $check),
            '--error-format=json',
            '--no-progress',
            ...$editing,
        ]));
    }

    /** The gate's own config, written where it is not there as it should be, including the project's. */
    private function checkConfig(): string|CannotJudge
    {
        $config = $this->config();
        $file = $this->absolute(Path::of(sprintf(self::CHECK, Workspace::root()->value())));
        $directory = dirname($file);
        $written = $config instanceof Path
            && (is_dir($directory) || (! file_exists($directory) && mkdir($directory, recursive: true)))
            && file_put_contents($file, sprintf(self::CHECK_CONFIG, $this->absolute($config))) !== false;

        return match (true) {
            $config instanceof CannotJudge => $config,
            ! $written => CannotJudge::because(sprintf(self::UNWRITTEN, $file)),
            default => $file,
        };
    }

    /** The config staticCheck.config names, or else the first PHPStan finds at the root. */
    private function config(): Path|CannotJudge
    {
        if ($this->config instanceof Path) {
            return $this->config;
        }

        foreach (BuiltinAnalyser::PhpStan->configs() as $candidate) {
            if (is_file($this->absolute($candidate))) {
                return $candidate;
            }
        }

        return CannotJudge::because(self::NO_CONFIG);
    }

    private function absolute(Path $path): string
    {
        return $this->root->at($path)->value();
    }
}
