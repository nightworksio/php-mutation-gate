<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpStan;

use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_dir;
use function is_file;
use function is_string;
use function is_writable;
use function mkdir;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserSettings;
use NightWorksIO\MutationGate\Core\Analysis\CheckAnswers;
use NightWorksIO\MutationGate\Core\Analysis\CheckBatch;
use NightWorksIO\MutationGate\Core\Analysis\FindingFiles;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\Analysis\MutantChecks;
use NightWorksIO\MutationGate\Core\Analysis\OutOfScope;
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
use NightWorksIO\MutationGate\Core\Runner\ChildProcess;
use NightWorksIO\MutationGate\Core\Runner\EnvironmentRead;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Withholding;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Port\Processes;
use NightWorksIO\MutationGate\Port\StaticChecker;

use const PHP_BINARY;

use function preg_match;
use function sprintf;
use function unlink;

/**
 * PHPStan, asked about a mutant in the mode its authors built for editors
 * (ADR-0020, decisions 6 and 7): `--tmp-file` stands the mutant in for its
 * original. It reads a config of the gate's own, which includes the
 * project's, analyses in one process, and does not report an ignore that
 * matched nothing. The warm-up analyses every file the config names and
 * saves PHPStan's result cache, so each check analyses the mutant and the
 * files that depend on it alone, and saves nothing. The warm-up also keeps
 * which files PHPStan analyses, and a mutant of any other is out of its
 * scope.
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

    /** Where the warm-up keeps PHPStan's parameters, which say which files it analyses. */
    private const string SCOPE = '%s/phpstan/scope.json';

    private const string UNDUMPED = 'PHPStan could not say which files it analyses (%s).';

    private const string NO_SCOPE = 'PHPStan\'s run over the originals has not said which files it analyses.';


    private const string UNRESOLVED = 'PHPStan could not say the configuration it runs with (%s).';

    private function __construct(private Root $root, private Path|Absent $config, private Processes $processes)
    {
    }

    /**
     * PHPStan in the project's root, reading the config the gate hands it as
     * `config`, or the one it finds, run through these processes.
     */
    public static function fromOptions(Options $options, string $root, Processes $processes): self|Invalid
    {
        $config = $options->path(Key::of('config'));

        return $config instanceof Problem
            ? Invalid::because($config)
            : new self(Root::of($root), $config instanceof NotGiven ? Absent::setting() : $config, $processes);
    }

    public function identity(Withheld $withheld): AnalyserIdentity|CannotJudge
    {
        $config = $this->config();
        $version = $this->ran($withheld, ['--version']);
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

    /**
     * The parameters PHPStan resolves from the project's config, its
     * includes and the environment, as `dump-parameters` says them, with
     * every file they name that PHPStan reads besides the code.
     */
    public function configuration(Withheld $withheld): AnalyserSettings|CannotJudge
    {
        $config = $this->config();
        $dumped = $config instanceof Path ? $this->ran($withheld, [
            'dump-parameters',
            '--json',
            sprintf('--configuration=%s', $this->absolute($config)),
        ]) : $config;

        return match (true) {
            $dumped instanceof CannotJudge => $dumped,
            ! $dumped->succeeded() => CannotJudge::because(sprintf(self::UNRESOLVED, $dumped->said())),
            default => Parameters::settings($dumped->output(), $this->root),
        };
    }

    /**
     * Every file the config names, analysed once, which saves the result
     * cache each check starts from, with the files PHPStan analyses kept for
     * the checks to read; or why it cannot say which those are.
     */
    public function findings(Paths $files, Withheld $withheld): Findings|CannotJudge
    {
        $check = $this->checkConfig();
        $scope = $check instanceof CannotJudge ? $check : $this->keptScope($withheld, $check);

        return $scope instanceof CannotJudge
            ? $scope
            : $this->analysed($withheld, FindingFiles::under($this->root), $check);
    }

    /** None: PHPStan analyses again the files whose view of the mutant changed, which it finds itself. */
    public function readsDependents(): bool
    {
        return false;
    }

    /** A mutant, where PHPStan analyses its original, as its warm-up said; out of scope where it does not. */
    public function check(MutantCheck $check): Findings|OutOfScope|CannotJudge
    {
        return $this->checks(MutantChecks::of($check), ProcessCount::single())->first();
    }

    /**
     * The mutants PHPStan analyses the originals of, as its warm-up said, each
     * analysed in a process of its own, side by side up to this many, from
     * the result cache the warm-up saved, which no check writes; the rest out
     * of scope.
     */
    public function checks(MutantChecks $checks, ProcessCount $side): CheckAnswers
    {
        $kept = is_file($this->scopeFile()) ? file_get_contents($this->scopeFile()) : false;
        $scope = is_string($kept) ? Scope::dumped($kept) : CannotJudge::because(self::NO_SCOPE);
        $config = $this->checkConfig();
        $prepared = [];

        foreach ($checks as $check) {
            $prepared[] = $this->prepared($check, $scope, $config);
        }

        $batch = CheckBatch::of(...$prepared);
        $all = [...$checks];

        return $batch->answered(
            $this->processes->sideBySide(
                WorkerSlots::of($side, BuiltinAnalyser::PhpStan->value),
                Unlimited::time(),
                ...$batch->commands(),
            ),
            fn(Ran $ran, int $at): Findings|CannotJudge => $this->answered($ran, $all[$at]),
            $this->processes->run(...),
        );
    }

    /**
     * The command that analyses a mutant in place of its original, within
     * its check's limit; out of scope where PHPStan does not analyse the
     * original, or why it cannot run.
     */
    private function prepared(
        MutantCheck $check,
        Scope|CannotJudge $scope,
        string|CannotJudge $config,
    ): ProcessCommand|OutOfScope|CannotJudge {
        $original = $this->absolute($check->original());

        return match (true) {
            $scope instanceof CannotJudge => $scope,
            $config instanceof CannotJudge => $config,
            ! $scope->holds($original) => OutOfScope::of($check->original()),
            default => $this->command(
                $check->withheld(),
                $this->analysing($config, [
                    sprintf('--tmp-file=%s', $this->absolute($check->mutant())),
                    sprintf('--instead-of=%s', $original),
                ]),
            )->within($check->limit()),
        };
    }

    /** What PHPStan reported of a check's analysis, or why it cannot judge: stopped at its limit, or no report. */
    private function answered(Ran $ran, MutantCheck $check): Findings|CannotJudge
    {
        $judged = new CheckLimit($check->limit())->judged(ChildProcess::of($ran));

        return $judged instanceof CannotJudge
            ? $judged
            : Report::of($judged, FindingFiles::under($this->root)->substituting($check));
    }

    /** The files PHPStan analyses, as this config's parameters say them, kept where each check reads them. */
    private function keptScope(Withheld $withheld, string $check): Scope|CannotJudge
    {
        $file = $this->scopeFile();

        if (is_file($file) && (! is_writable(dirname($file)) || ! unlink($file))) {
            return CannotJudge::because(sprintf(self::UNWRITTEN, $file));
        }

        $dumped = $this->ran($withheld, [
            'dump-parameters',
            '--json',
            sprintf('--configuration=%s', $check),
        ]);
        $scope = $dumped->succeeded()
            ? Scope::dumped($dumped->output())
            : CannotJudge::because(sprintf(self::UNDUMPED, $dumped->said()));

        $unkept = $scope instanceof Scope && (is_dir($file) || file_put_contents($file, $dumped->output()) === false);

        return $unkept ? CannotJudge::because(sprintf(self::UNWRITTEN, $file)) : $scope;
    }

    /** Where the warm-up keeps the files PHPStan analyses, for each check. */
    private function scopeFile(): string
    {
        return $this->absolute(Path::of(sprintf(self::SCOPE, Workspace::root()->value())));
    }

    /**
     * What PHPStan reports of every file this config of the gate's names,
     * each finding in the file it sits in.
     */
    private function analysed(Withheld $withheld, FindingFiles $files, string $config): Findings|CannotJudge
    {
        $judged = new CheckLimit()->judged($this->ran($withheld, $this->analysing($config, [])));

        return $judged instanceof CannotJudge ? $judged : Report::of($judged, $files);
    }

    /**
     * PHPStan's analysis, reading the gate's config, in JSON, given these editing arguments.
     *
     * @param  list<string> $editing
     * @return list<string>
     */
    private function analysing(string $config, array $editing): array
    {
        return [
            'analyse',
            sprintf('--configuration=%s', $config),
            '--error-format=json',
            '--no-progress',
            ...$editing,
        ];
    }

    /**
     * PHPStan, given these arguments, as the Processes port runs it in the
     * project's root, without what is withheld.
     *
     * @param list<string> $arguments
     */
    private function command(Withheld $withheld, array $arguments): ProcessCommand
    {
        return ProcessCommand::of($this->root->value(), PHP_BINARY, self::SCRIPT, ...$arguments)
            ->with(EnvironmentRead::of(Withholding::of($withheld, getenv())));
    }

    /**
     * PHPStan, given these arguments, run to its end.
     *
     * @param list<string> $arguments
     */
    private function ran(Withheld $withheld, array $arguments): ChildProcess
    {
        return ChildProcess::of($this->processes->run($this->command($withheld, $arguments)));
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
