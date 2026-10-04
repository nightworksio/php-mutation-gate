<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Psalm;

use function array_key_exists;
use function getmypid;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;

use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Psalm's language server, `psalm-language-server`, spoken to over its
 * standard streams (ADR-0020, decision 7). It opens a file with the text the
 * gate gives it, or changes an open file's text, and analyses it as it
 * reads the message, publishing the file's diagnostics with the version of
 * the text it read, where the file is one it analyses, and nothing where it
 * is not; a file on disk is never written. Started without a debounce, it
 * handles each message in turn, so its answer to a request the gate sends
 * after the files comes after every diagnostic they cause: the request is
 * one no server implements, which the protocol has every server answer
 * with an error. The gate answers each request the server makes with
 * nothing, and lets every other message go by. It is started once, and kept
 * running, warm, for the checks that follow.
 */
final class LanguageServer
{
    private const string VERSION = '2.0';

    private const string NOT_STARTED = 'Psalm\'s language server did not start (%s).';

    private const string ENDED = 'Psalm\'s language server ended before it answered (%s).';

    private const string PUBLISHED = 'textDocument/publishDiagnostics';

    /** A request no server implements, which the protocol has a server answer with an error, as its `$/` says. */
    private const string FENCE = '$/mutationGate/analysed';

    /** What the gate tells the server it reads: each diagnostic's version, and its issue type in `data`. */
    private const string CAPABILITIES
        = '{"textDocument":{"publishDiagnostics":{"versionSupport":true,"dataSupport":true}}}';

    /** The language of every file the gate sends. */
    private const string PHP = 'php';

    /** What the stream carried of a message not yet whole. */
    private string $carried = '';

    private int $requests = 0;

    /** @var array<string, int> the version of each file's text the server was last sent, by its URI */
    private array $versions = [];

    /**
     * @var array<string, array{int, Node}> the diagnostics the server last
     *                                     published of each file, with their
     *                                     version, by its URI
     */
    private array $published = [];

    /** The id of the last request of the gate's the server answered; the gate awaits each before the next. */
    private int $answered = 0;

    private function __construct(private readonly Process $process, private readonly InputStream $input)
    {
    }

    public function __destruct()
    {
        $this->input->close();
        $this->process->stop();
    }

    /**
     * The server, started in the project's root without what is withheld,
     * and initialised; or why it is not.
     *
     * @param list<string>          $command
     * @param array<string, string|false> $environment
     */
    public static function started(array $command, string $root, array $environment): self|CannotJudge
    {
        $input = new InputStream();
        $process = new Process($command, $root, $environment, $input, timeout: null);

        try {
            $process->start();
        } catch (RuntimeException $failure) {
            return CannotJudge::because(sprintf(self::NOT_STARTED, $failure->getMessage()));
        }

        return new self($process, $input)->initialized($root);
    }

    /**
     * What the server publishes of each of these files read with this text,
     * at the version it was sent, by its URI, for each file it analyses; or
     * why it did not answer.
     *
     * @param  array<string, string>     $texts each file's text, by its absolute path
     * @return array<string, Node>|CannotJudge
     */
    public function analysed(array $texts): array|CannotJudge
    {
        $sent = [];

        foreach ($texts as $path => $text) {
            $uri = Diagnostics::uriOf($path);
            $sent[$uri] = $this->sent($uri, $text);
        }

        $fence = $this->request(self::FENCE, JsonText::object([]));
        $answered = $this->awaited(fn(): bool => $this->answered === $fence);

        if ($answered instanceof CannotJudge) {
            return $answered;
        }

        $published = [];

        foreach ($sent as $uri => $version) {
            $current = array_key_exists($uri, $this->published) && $this->published[$uri][0] === $version;
            $published += $current ? [$uri => $this->published[$uri][1]] : [];
        }

        return $published;
    }

    /**
     * These files' texts sent again, with nothing awaited: diagnostics are
     * read only at the version a later check sends, so none is stale.
     *
     * @param array<string, string> $texts each file's text, by its absolute path
     */
    public function restore(array $texts): void
    {
        foreach ($texts as $path => $text) {
            $this->sent(Diagnostics::uriOf($path), $text);
        }
    }

    /** The server, once it answered its initialisation, told what the gate reads; or why not. */
    private function initialized(string $root): self|CannotJudge
    {
        $pid = getmypid();
        $params = JsonText::object([
            'processId' => $pid === false ? 'null' : sprintf('%d', $pid),
            'rootUri' => JsonText::text(Diagnostics::uriOf($root)),
            'capabilities' => self::CAPABILITIES,
        ]);
        $id = $this->request('initialize', $params);
        $answered = $this->awaited(fn(): bool => $this->answered === $id);

        if ($answered instanceof CannotJudge) {
            return $answered;
        }

        $this->notify('initialized', JsonText::object([]));

        return $this;
    }

    /** Send a file's text, opening it the first time, and say the version it was sent at. */
    private function sent(string $uri, string $text): int
    {
        $opened = array_key_exists($uri, $this->versions);
        $version = $opened ? $this->versions[$uri] + 1 : 1;
        $this->versions[$uri] = $version;
        $whole = JsonText::text($text);
        $document = JsonText::object([
            'uri' => JsonText::text($uri),
            'version' => sprintf('%d', $version),
            ...$opened ? [] : ['languageId' => JsonText::text(self::PHP), 'text' => $whole],
        ]);
        $changes = JsonText::items([JsonText::object(['text' => $whole])]);
        $params = JsonText::object(['textDocument' => $document, ...$opened ? ['contentChanges' => $changes] : []]);

        $this->notify($opened ? 'textDocument/didChange' : 'textDocument/didOpen', $params);

        return $version;
    }

    /**
     * Read what the server says until this holds, handling each message as
     * it comes; or why it ended first. Each read waits for what the server
     * writes next, or its end, and takes all it wrote since the last, so
     * what it writes is never kept past its handling.
     *
     * @param callable(): bool $holds
     */
    private function awaited(callable $holds): true|CannotJudge
    {
        while (! $holds()) {
            $written = $this->process->getIterator(Process::ITER_SKIP_ERR);

            if (! $written->valid()) {
                return CannotJudge::because(sprintf(self::ENDED, $this->process->getErrorOutput()));
            }

            $this->read($written->current());
        }

        $this->process->clearErrorOutput();

        return true;
    }

    /** Handle each whole message in what the server wrote, keeping what is not yet whole for the next read. */
    private function read(string $output): void
    {
        $frames = Frames::read(sprintf('%s%s', $this->carried, $output));
        $this->carried = $frames->rest();

        foreach ($frames->bodies() as $body) {
            $this->handled(Node::decode($body));
        }
    }

    /** Keep what a message says the gate waits for, and answer a request of the server's with nothing. */
    private function handled(Node $message): void
    {
        $method = Lenient::text($message->field('method'));
        $id = $message->field('id');

        if ($method === self::PUBLISHED) {
            $this->keptPublished($message->field('params'));

            return;
        }

        if ($method !== '' && $id->isPresent()) {
            $this->write(JsonText::object([
                'jsonrpc' => JsonText::text(self::VERSION),
                'id' => Lenient::json($id),
                'result' => 'null',
            ]));

            return;
        }

        if ($id->kind() === Kind::Integer) {
            $this->answered = Lenient::integer($id);
        }
    }

    private function keptPublished(Node $params): void
    {
        $version = Lenient::integer($params->field('version'));
        $this->published[Lenient::text($params->field('uri'))] = [$version, $params];
    }

    /** Make a request of the server, and say its id. */
    private function request(string $method, string $params): int
    {
        $this->requests++;
        $this->write(JsonText::object([
            'jsonrpc' => JsonText::text(self::VERSION),
            'id' => sprintf('%d', $this->requests),
            'method' => JsonText::text($method),
            'params' => $params,
        ]));

        return $this->requests;
    }

    private function notify(string $method, string $params): void
    {
        $this->write(JsonText::object([
            'jsonrpc' => JsonText::text(self::VERSION),
            'method' => JsonText::text($method),
            'params' => $params,
        ]));
    }

    private function write(string $json): void
    {
        $this->input->write(Frames::framed($json));
    }
}
