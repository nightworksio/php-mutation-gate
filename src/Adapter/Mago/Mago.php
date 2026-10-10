<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Mago;

use function file_get_contents;
use function getenv;
use function is_file;
use function is_string;

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
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ChildProcess;
use NightWorksIO\MutationGate\Core\Runner\Environment;
use NightWorksIO\MutationGate\Core\Runner\EnvironmentRead;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Withholding;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Port\Processes;
use NightWorksIO\MutationGate\Port\StaticChecker;

use function preg_match;
use function sprintf;

/**
 * Mago, asked about a mutant with `--substitute`, the option its authors
 * built for mutation testing (ADR-0020, decisions 6 and 7): the mutant
 * stands in for its original while Mago analyses the whole workspace, in
 * one thread, with the config passed on every run. Mago analyses a
 * substitute outside the paths it is configured with too, so a mutant of
 * such a file is left unchecked, and says so. The gate runs the binary
 * Composer's package downloaded, and never downloads it itself (decision 18).
 */
final readonly class Mago implements StaticChecker
{
    private const string VERSION = '~^mago (\S+)~';

    private const string UNVERSIONED = 'Mago did not say its version (%s).';

    private const string OUTSIDE = '%s is outside the paths Mago analyses, so Mago leaves it unchecked.';

    private const string UNREAD = 'Mago\'s config %s cannot be read.';



    private function __construct(
        private Root $root,
        private string|CannotJudge $binary,
        private Path|Absent $config,
        private Processes $processes,
    ) {
    }

    /** Mago in the project's root, reading the config the gate hands it as `config`, or the one it finds. */
    public static function fromOptions(
        Options $options,
        string $root,
        string $vendor,
        Processes $processes,
    ): self|Invalid {
        $config = $options->path(Key::of('config'));

        return $config instanceof Problem
            ? Invalid::because($config)
            : new self(
                Root::of($root),
                Binary::in($vendor),
                $config instanceof NotGiven ? Absent::setting() : $config,
                $processes,
            );
    }

    public function identity(Withheld $withheld): AnalyserIdentity|CannotJudge
    {
        $config = $this->config();

        if (! $config instanceof Path) {
            return $this->identified($withheld, Digest::sha256Of(''));
        }

        $contents = is_file($this->absolute($config)) ? file_get_contents($this->absolute($config)) : false;

        return is_string($contents)
            ? $this->identified($withheld, Digest::sha256Of($contents))
            : CannotJudge::because(sprintf(self::UNREAD, $config->value()));
    }

    /**
     * The configuration Mago runs with, as `mago config` merges it from its
     * config file, the environment and its defaults, with the files it
     * names besides the code: its config file, the analyser's baseline, and
     * the included and patched sources.
     */
    public function configuration(Withheld $withheld): AnalyserSettings|CannotJudge
    {
        $config = $this->config();

        return Configuration::shown(
            $this->mago($withheld, ['--threads=1', 'config']),
            $this->root,
            ...$config instanceof Path ? [$this->absolute($config)] : [],
        );
    }

    /** The whole workspace, analysed once, which holds every one of these files, or cannot judge. */
    public function findings(Paths $files, Withheld $withheld): Findings|CannotJudge
    {
        $outside = $this->withinScope($files, $withheld);

        return match (true) {
            $outside instanceof OutOfScope => CannotJudge::because(sprintf(self::OUTSIDE, $outside->file()->value())),
            $outside instanceof CannotJudge => $outside,
            default => $this->analysed($withheld, FindingFiles::under($this->root), []),
        };
    }

    /** None: Mago analyses the whole workspace in each check. */
    public function readsDependents(): bool
    {
        return false;
    }

    /**
     * A mutant, where Mago analyses its original; out of scope where it does
     * not. The listing and the analysis each take at most the check's limit,
     * and a check stopped there cannot judge.
     */
    public function check(MutantCheck $check): Findings|OutOfScope|CannotJudge
    {
        return $this->checks(MutantChecks::of($check), ProcessCount::single())->first();
    }

    /**
     * The mutants Mago analyses the originals of, each analysed in a process
     * of its own, side by side up to this many, within its check's limit;
     * the rest out of scope. Mago lists the files it analyses once for them
     * all, within the first check's limit.
     */
    public function checks(MutantChecks $checks, ProcessCount $side): CheckAnswers
    {
        $listed = [];
        $prepared = [];

        foreach ($checks as $check) {
            $listed = $listed === [] ? [$this->analysedFiles($check->withheld(), $check->limit())] : $listed;
            $prepared[] = $this->prepared($check, $listed[0]);
        }

        $batch = CheckBatch::of(...$prepared);
        $all = [...$checks];

        $slots = WorkerSlots::of($side, BuiltinAnalyser::Mago->value);

        return $batch->answered(
            $this->processes->sideBySide($slots, Unlimited::time(), ...$batch->commands()),
            fn(Ran $ran, int $at): Findings|CannotJudge => $this->answered($ran, $all[$at]),
            $this->processes->run(...),
        );
    }

    /**
     * The command that analyses a mutant in place of its original, within
     * its check's limit; out of scope where Mago does not analyse the
     * original, or why it cannot say.
     */
    private function prepared(MutantCheck $check, Paths|CannotJudge $analysed): ProcessCommand|OutOfScope|CannotJudge
    {
        if ($analysed instanceof CannotJudge) {
            return $analysed;
        }

        $original = $check->original();
        $command = $this->command($check->withheld(), [
            '--threads=1',
            'analyze',
            '--reporting-format=json',
            '--substitute',
            sprintf('%s=%s', $this->absolute($original), $this->absolute($check->mutant())),
        ]);

        return match (true) {
            ! $analysed->has($this->root->relative($this->absolute($original))) => OutOfScope::of($original),
            $command instanceof CannotJudge => $command,
            default => $command->within($check->limit()),
        };
    }

    /** What Mago reported of a check's analysis, or why it cannot judge: stopped at its limit, or no report. */
    private function answered(Ran $ran, MutantCheck $check): Findings|CannotJudge
    {
        $limit = $check->limit();

        return $ran->wasStopped() && $limit instanceof Seconds
            ? CheckLimit::from($limit)->unfinished()
            : Report::of(ChildProcess::of($ran), FindingFiles::under($this->root)->substituting($check));
    }

    /**
     * Mago in the workspace, with its config and no colours, given these
     * arguments, as the Processes port runs it, without what is withheld.
     *
     * @param list<string> $arguments
     */
    private function command(Withheld $withheld, array $arguments): ProcessCommand|CannotJudge
    {
        $binary = $this->binary;
        $config = $this->config();

        $configured = $config instanceof Path ? [sprintf('--config=%s', $this->absolute($config))] : [];

        return is_string($binary) ? ProcessCommand::of(
            $this->root->value(),
            ...[$binary, sprintf('--workspace=%s', $this->root->value()), ...$configured],
            ...['--colors=never', ...$arguments],
        )->with($this->withholding($withheld)) : $binary;
    }

    /**
     * These files, where Mago analyses every one of them; the first it does
     * not, or why it cannot say.
     */
    private function withinScope(Paths $files, Withheld $withheld): Paths|OutOfScope|CannotJudge
    {
        $analysed = $this->analysedFiles($withheld);

        if ($analysed instanceof CannotJudge) {
            return $analysed;
        }

        foreach ($files as $file) {
            if (! $analysed->has($this->root->relative($this->absolute($file)))) {
                return OutOfScope::of($file);
            }
        }

        return $files;
    }

    /**
     * Every file Mago analyses, as it lists them, or why it cannot say: one
     * listing stopped at a check's limit does not finish the check.
     */
    private function analysedFiles(Withheld $withheld, Seconds|Unlimited $limit = new Unlimited()): Paths|CannotJudge
    {
        $command = $this->command($withheld, ['list-files', '-0']);
        $listed = $command instanceof CannotJudge ? $command : $this->ran($command->within($limit));

        return FileList::read($listed, $limit);
    }

    /** Mago's name and version, as its binary says them, with the digest of the config it reads. */
    private function identified(Withheld $withheld, Digest $config): AnalyserIdentity|CannotJudge
    {
        $binary = $this->binary;
        $version = is_string($binary) ? $this->ran(
            ProcessCommand::of($this->root->value(), $binary, '--version')->with($this->withholding($withheld)),
        ) : $binary;

        return match (true) {
            $version instanceof CannotJudge => $version,
            preg_match(self::VERSION, $version->output(), $said) !== 1 => CannotJudge::because(
                sprintf(self::UNVERSIONED, $version->said()),
            ),
            default => AnalyserIdentity::of(BuiltinAnalyser::Mago->value, $said[1], $config),
        };
    }

    /**
     * What Mago reports of the whole workspace, each finding in the file it
     * sits in, given these arguments.
     *
     * @param list<string> $arguments
     */
    private function analysed(Withheld $withheld, FindingFiles $files, array $arguments): Findings|CannotJudge
    {
        $ran = $this->mago($withheld, ['--threads=1', 'analyze', '--reporting-format=json', ...$arguments]);

        return $ran instanceof CannotJudge ? $ran : Report::of($ran, $files);
    }

    /**
     * Mago in the workspace, with its config and no colours, given these arguments, run to its end.
     *
     * @param list<string> $arguments
     */
    private function mago(Withheld $withheld, array $arguments): ChildProcess|CannotJudge
    {
        $command = $this->command($withheld, $arguments);

        return $command instanceof CannotJudge ? $command : $this->ran($command);
    }

    /** A command, run by the Processes port to its end in the project's root. */
    private function ran(ProcessCommand $command): ChildProcess
    {
        return ChildProcess::of($this->processes->run($command));
    }

    /** The environment Mago runs in: the gate's, without what is withheld. */
    private function withholding(Withheld $withheld): Environment
    {
        return EnvironmentRead::of(Withholding::of($withheld, getenv()));
    }

    /** The config staticCheck.config names, or else the first Mago finds at the root, or none, for Mago's defaults. */
    private function config(): Path|Absent
    {
        if ($this->config instanceof Path) {
            return $this->config;
        }

        foreach (BuiltinAnalyser::Mago->configs() as $candidate) {
            if (is_file($this->absolute($candidate))) {
                return $candidate;
            }
        }

        return Absent::setting();
    }

    private function absolute(Path $path): string
    {
        return $this->root->at($path)->value();
    }
}
