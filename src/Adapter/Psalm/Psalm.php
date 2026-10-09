<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Psalm;

use function file_get_contents;
use function getenv;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserSettings;
use NightWorksIO\MutationGate\Core\Analysis\CheckAnswers;
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
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ChildProcess;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Withholding;
use NightWorksIO\MutationGate\Port\StaticChecker;

use const PHP_BINARY;

use function preg_match;
use function realpath;
use function sprintf;

use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Psalm, asked about a mutant through its language server (ADR-0020,
 * decisions 6 and 7): the server, started once and kept warm, reads the
 * mutant as its original's changed text, and each dependent the check lists
 * that Psalm analyses with its own text again, so it re-analyses those files
 * against the mutant, and the original's text is sent back after. The run
 * over the originals is Psalm's command line, reporting every file it
 * analyses, its baseline set aside, so a finding a baseline hides is never
 * read as new; a check reports what the server published of each file it
 * sent, and those findings for every other file. A mutant of a file outside
 * the `<projectFiles>` of the project's `psalm.xml`, or among their
 * `<ignoreFiles>`, or one whose original the server publishes nothing for,
 * is out of its scope.
 */
final class Psalm implements StaticChecker
{
    private const string SCRIPT = 'vendor/bin/psalm';

    private const string SERVER = 'vendor/bin/psalm-language-server';

    private const string VERSION = '~^Psalm (\S+)@~m';

    /**
     * The server's analysis kept in memory alone: without it, the server
     * removes the project's Psalm cache as it starts, and holds a lock on the
     * cache it then writes for as long as it runs, which a later Psalm
     * process waits on.
     */
    private const string IN_MEMORY = '--in-memory=true';

    /** What the server does for an editor that a check needs none of. */
    private const array EDITOR_ONLY = [
        '--enable-autocomplete=false',
        '--enable-code-actions=false',
        '--enable-provide-hover=false',
        '--enable-provide-signature-help=false',
        '--enable-provide-definition=false',
    ];

    private const string UNVERSIONED = 'Psalm did not say its version (%s).';

    private const string NO_CONFIG = 'Psalm has no config to read: add a psalm.xml, or name one in staticCheck.config.';

    private const string UNREAD = 'Psalm\'s config %s cannot be read.';

    private const string OUTSIDE = '%s is outside the files Psalm analyses, so Psalm leaves it unchecked.';

    private const string NO_WARM_UP = 'Psalm has not run over the originals, so no mutant can be compared with them.';

    /** The server once started, or why it could not start; none before the first check. */
    private LanguageServer|CannotJudge|NotGiven $server;

    /** What the run over the originals found; nothing before it ran. */
    private Findings|NotGiven $originals;

    private function __construct(private readonly Root $root, private readonly Path|Absent $config)
    {
        $this->server = NotGiven::value();
        $this->originals = NotGiven::value();
    }

    /**
     * Psalm in the project's root, spelt as Psalm spells the files it
     * reports, with every link resolved, reading the config the gate hands
     * it as `config`, or the one it finds.
     */
    public static function fromOptions(Options $options, string $root): self|Invalid
    {
        $config = $options->path(Key::of('config'));
        $real = realpath($root);
        $resolved = Root::of($real === false ? $root : $real);

        return $config instanceof Problem
            ? Invalid::because($config)
            : new self($resolved, $config instanceof NotGiven ? Absent::setting() : $config);
    }

    public function identity(Withheld $withheld): AnalyserIdentity|CannotJudge
    {
        $path = $this->configPath();
        $config = $path instanceof Path ? $this->configText($path) : CannotJudge::because(self::NO_CONFIG);
        $version = $this->ran($withheld, [PHP_BINARY, self::SCRIPT, '--version']);

        return match (true) {
            $config instanceof CannotJudge => $config,
            preg_match(self::VERSION, $version->output(), $said) !== 1 => CannotJudge::because(
                sprintf(self::UNVERSIONED, $version->said()),
            ),
            default => AnalyserIdentity::of(BuiltinAnalyser::Psalm->value, $said[1], Digest::sha256Of($config->text())),
        };
    }

    /** The configuration Psalm runs with, read from its config as Psalm reads it, with the files it names. */
    public function configuration(Withheld $withheld): AnalyserSettings|CannotJudge
    {
        $xml = $this->xml();

        return $xml instanceof PsalmXml ? $xml->settings($this->root->value()) : $xml;
    }

    /**
     * Every file Psalm analyses, analysed once on its command line with its
     * baseline set aside, where it analyses each of these files; or why not.
     */
    public function findings(Paths $files, Withheld $withheld): Findings|CannotJudge
    {
        $xml = $this->xml();

        if ($xml instanceof CannotJudge) {
            return $xml;
        }

        foreach ($files as $file) {
            if (! $xml->holds($this->absolute($file))) {
                return CannotJudge::because(sprintf(self::OUTSIDE, $file->value()));
            }
        }

        $ran = $this->ran($withheld, [
            PHP_BINARY,
            self::SCRIPT,
            sprintf('--config=%s', $this->absolute($xml->file())),
            '--output-format=json',
            '--no-progress',
            '--ignore-baseline',
            '--show-info=true',
        ]);
        $found = Report::of($ran, FindingFiles::under($this->root), $xml);
        $this->originals = $found instanceof Findings ? $found : NotGiven::value();

        return $found;
    }

    /** All: Psalm's server analyses again only the files it is sent. */
    public function readsDependents(): bool
    {
        return true;
    }

    /** A mutant, where Psalm analyses its original; out of scope where it does not. */
    public function check(MutantCheck $check): Findings|OutOfScope|CannotJudge
    {
        $xml = $this->xml();
        $originals = $this->originals;

        return match (true) {
            $xml instanceof CannotJudge => $xml,
            ! $xml->holds($this->absolute($check->original())) => OutOfScope::of($check->original()),
            ! $originals instanceof Findings => CannotJudge::because(self::NO_WARM_UP),
            default => $this->checked($check, $originals, $xml),
        };
    }

    /** These mutants, checked in turn over the one language server, which holds one file's text at a time. */
    public function checks(MutantChecks $checks, ProcessCount $side): CheckAnswers
    {
        $answers = [];

        foreach ($checks as $check) {
            $answers[] = $this->check($check);
        }

        return CheckAnswers::of(...$answers);
    }

    /**
     * What the server says of the mutant and its dependents, beside what the
     * originals' run found of every other file; out of scope where the
     * server, which reads its config itself, does not analyse the original.
     */
    private function checked(MutantCheck $check, Findings $originals, PsalmXml $xml): Findings|OutOfScope|CannotJudge
    {
        $sent = Sent::of($check, $this->root, $xml);

        if (! $sent instanceof Sent) {
            return $sent;
        }

        $server = $this->server($check, $xml);

        if (! $server instanceof LanguageServer) {
            return $server;
        }

        $published = $this->published($server, $sent, $check);

        return match (true) {
            $published instanceof CannotJudge => $published,
            ! $sent->analysedIn($published) => OutOfScope::of($check->original()),
            default => $sent->found($originals, $published, $this->root),
        };
    }

    /**
     * What the server publishes of the files a check sends, with their texts
     * sent again after; or why it did not answer. A server stopped because it
     * did not answer in time is let go, for the next check to start another.
     *
     * @return array<string, Node>|CannotJudge
     */
    private function published(LanguageServer $server, Sent $sent, MutantCheck $check): array|CannotJudge
    {
        $published = $server->analysed($sent->texts(), $check->limit());

        if ($server->wasStopped()) {
            $this->server = NotGiven::value();

            return $published;
        }

        $server->restore($sent->restoring());

        return $published;
    }

    /**
     * The server, started the first time it is needed, and again after one
     * stopped because it did not answer a check in time, without what is
     * withheld and within the check's limit; or why it could not start.
     */
    private function server(MutantCheck $check, PsalmXml $xml): LanguageServer|CannotJudge
    {
        if ($this->server instanceof NotGiven) {
            $this->server = LanguageServer::started(
                [
                    PHP_BINARY,
                    self::SERVER,
                    sprintf('--config=%s', $this->absolute($xml->file())),
                    self::IN_MEMORY,
                    ...self::EDITOR_ONLY,
                ],
                $this->root->value(),
                Withholding::of($check->withheld(), getenv()),
                $check->limit(),
            );
        }

        return $this->server;
    }

    private function xml(): PsalmXml|CannotJudge
    {
        $config = $this->configPath();

        if (! $config instanceof Path) {
            return CannotJudge::because(self::NO_CONFIG);
        }

        $text = $this->configText($config);

        return $text instanceof Contents ? PsalmXml::read($text, $config, $this->root) : $text;
    }

    /** What the config holds, or why it cannot be read. */
    private function configText(Path $config): Contents|CannotJudge
    {
        $at = $this->absolute($config);
        $text = is_file($at) ? file_get_contents($at) : false;

        return is_string($text) ? Contents::of($text) : CannotJudge::because(sprintf(self::UNREAD, $config->value()));
    }

    /** The config staticCheck.config names, or else the first Psalm finds at the root. */
    private function configPath(): Path|Absent
    {
        if ($this->config instanceof Path) {
            return $this->config;
        }

        foreach (BuiltinAnalyser::Psalm->configs() as $candidate) {
            if (is_file($this->absolute($candidate))) {
                return $candidate;
            }
        }

        return Absent::setting();
    }

    /**
     * A command, run to its end in the project's root without what is withheld.
     *
     * @param list<string> $arguments
     */
    private function ran(Withheld $withheld, array $arguments): ChildProcess
    {
        $process = new Process($arguments, $this->root->value(), Withholding::of($withheld, getenv()), timeout: null);

        try {
            return ChildProcess::exited($process->run(), $process->getOutput(), $process->getErrorOutput());
        } catch (RuntimeException $failure) {
            return ChildProcess::neverStarted($failure->getMessage());
        }
    }

    private function absolute(Path $path): string
    {
        return $this->root->at($path)->value();
    }
}
