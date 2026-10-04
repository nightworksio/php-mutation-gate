<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Mago;

use function array_filter;
use function array_map;
use function explode;
use function file_get_contents;
use function getenv;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserSettings;
use NightWorksIO\MutationGate\Core\Analysis\FindingFiles;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
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
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Withholding;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Port\StaticChecker;

use function preg_match;
use function sprintf;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

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

    private const string UNLISTED = 'Mago could not list the files it analyses (%s).';

    private const string UNREAD = 'Mago\'s config %s cannot be read.';

    private function __construct(private Root $root, private string|CannotJudge $binary, private Path|Absent $config)
    {
    }

    /** Mago in the project's root, reading the config the gate hands it as `config`, or the one it finds. */
    public static function fromOptions(Options $options, string $root, string $vendor): self|Invalid
    {
        $config = $options->path(Key::of('config'));

        return $config instanceof Problem
            ? Invalid::because($config)
            : new self(
                Root::of($root),
                Binary::in($vendor),
                $config instanceof NotGiven ? Absent::setting() : $config,
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
     * not. The listing and the analysis together take at most the check's
     * limit, and a check stopped there cannot judge.
     */
    public function check(MutantCheck $check): Findings|OutOfScope|CannotJudge
    {
        $limit = $check->limit();
        $bound = $limit instanceof Seconds ? CheckLimit::from($limit) : $limit;
        $outside = $this->withinScope(Paths::of($check->original()), $check->withheld(), $bound);
        $files = FindingFiles::under($this->root)->substituting($check);

        return $outside instanceof Paths ? $this->analysed($check->withheld(), $files, [
            '--substitute',
            sprintf('%s=%s', $this->absolute($check->original()), $this->absolute($check->mutant())),
        ], $bound) : $outside;
    }

    /**
     * These files, where Mago analyses every one of them; the first it does
     * not, or why it cannot say.
     */
    private function withinScope(
        Paths $files,
        Withheld $withheld,
        CheckLimit|Unlimited $limit = new Unlimited(),
    ): Paths|OutOfScope|CannotJudge {
        $analysed = $this->analysedFiles($withheld, $limit);

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

    /** Every file Mago analyses, as it lists them, or why it cannot say. */
    private function analysedFiles(Withheld $withheld, CheckLimit|Unlimited $limit): Paths|CannotJudge
    {
        $listed = $this->mago($withheld, ['list-files', '-0'], $limit);

        return match (true) {
            $listed instanceof ChildProcess && $listed->wasStopped() && $limit instanceof CheckLimit
                => $limit->unfinished(),
            $listed instanceof CannotJudge || $listed->exit() !== 0 => CannotJudge::because(sprintf(
                self::UNLISTED,
                $listed instanceof CannotJudge ? $listed->why() : $listed->said(),
            )),
            default => Paths::of(...array_map(
                Path::of(...),
                array_filter(explode("\0", $listed->output()), static fn(string $file): bool => $file !== ''),
            )),
        };
    }

    /** Mago's name and version, as its binary says them, with the digest of the config it reads. */
    private function identified(Withheld $withheld, Digest $config): AnalyserIdentity|CannotJudge
    {
        $binary = $this->binary;
        $version = is_string($binary) ? $this->ran($withheld, [$binary, '--version']) : $binary;

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
    private function analysed(
        Withheld $withheld,
        FindingFiles $files,
        array $arguments,
        CheckLimit|Unlimited $limit = new Unlimited(),
    ): Findings|CannotJudge {
        $ran = $this->mago($withheld, ['--threads=1', 'analyze', '--reporting-format=json', ...$arguments], $limit);

        return match (true) {
            $ran instanceof CannotJudge => $ran,
            $ran->wasStopped() && $limit instanceof CheckLimit => $limit->unfinished(),
            default => Report::of($ran, $files),
        };
    }

    /**
     * Mago in the workspace, with its config and no colours, given these arguments.
     *
     * @param list<string> $arguments
     */
    private function mago(
        Withheld $withheld,
        array $arguments,
        CheckLimit|Unlimited $limit = new Unlimited(),
    ): ChildProcess|CannotJudge {
        $binary = $this->binary;
        $config = $this->config();

        return is_string($binary) ? $this->ran($withheld, [
            $binary,
            sprintf('--workspace=%s', $this->root->value()),
            ...$config instanceof Path ? [sprintf('--config=%s', $this->absolute($config))] : [],
            '--colors=never',
            ...$arguments,
        ], $limit) : $binary;
    }

    /**
     * A command, run to its end in the project's root without what is
     * withheld, or stopped once what is left of a check's limit has passed.
     *
     * @param list<string> $arguments
     */
    private function ran(
        Withheld $withheld,
        array $arguments,
        CheckLimit|Unlimited $limit = new Unlimited(),
    ): ChildProcess {
        $process = new Process(
            $arguments,
            $this->root->value(),
            Withholding::of($withheld, getenv()),
            timeout: $limit instanceof CheckLimit ? $limit->left()->seconds() : null,
        );

        try {
            return ChildProcess::exited($process->run(), $process->getOutput(), $process->getErrorOutput());
        } catch (ProcessTimedOutException) {
            return ChildProcess::stopped($process->getOutput(), $process->getErrorOutput());
        } catch (RuntimeException $failure) {
            return ChildProcess::neverStarted($failure->getMessage());
        }
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
