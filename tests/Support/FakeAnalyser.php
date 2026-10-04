<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function chmod;
use function glob;

use const PHP_BINARY;

use function sprintf;

/**
 * A project with a stand-in for an analyser's command: a PHP script that
 * says the version it is given for `--version`, lists the files it is given
 * for `list-files`, dumps the parameters it is given for `dump-parameters`,
 * or else `src` under the project as PHPStan's one path, and otherwise writes the answer it is given and exits as
 * told, keeping the arguments it was run with in `argv.txt` beside it. `files.sleep` and `answer.sleep` make it
 * wait that many seconds before it lists or answers.
 */
final readonly class FakeAnalyser
{
    /** The psalm.xml of a project {@see psalm()} makes. */
    private const string PSALM_XML = <<<'XML'
        <?xml version="1.0"?>
        <psalm errorLevel="1" xmlns="https://getpsalm.org/schema/config">
            <projectFiles>
                <directory name="src"/>
                <ignoreFiles>
                    <file name="src/Excluded.php"/>
                </ignoreFiles>
            </projectFiles>
        </psalm>
        XML;

    private const string SCRIPT = <<<'PHP'
        <?php
        $arguments = array_slice($argv, 1);
        $here = __DIR__;
        $said = static fn(string $name): string => (string) @file_get_contents(sprintf('%s/%s', $here, $name));
        $leaked = getenv('MUTATION_GATE_CONTRACT_TOKEN') !== false ? 'leaked' : 'withheld';
        file_put_contents(sprintf('%s/argv.txt', $here), implode("\n", [...$arguments, $leaked]));
        if (in_array('--version', $arguments, true)) {
            echo $said('version.txt');
            exit(0);
        }
        if (in_array('dump-parameters', $arguments, true)) {
            $project = dirname($here, 2);
            $default = sprintf('{"paths": ["%s/src"], "excludePaths": {"analyseAndScan": [], "analyse": []}}', $project);
            echo is_file(sprintf('%s/params.json', $here)) ? $said('params.json') : $default;
            exit((int) $said('params.exit'));
        }
        if (in_array('list-files', $arguments, true)) {
            usleep((int) ((float) $said('files.sleep') * 1000000));
            echo $said('files.txt');
            exit((int) $said('files.exit'));
        }
        usleep((int) ((float) $said('answer.sleep') * 1000000));
        echo $said('answer.json');
        fwrite(STDERR, $said('answer.err'));
        exit((int) $said('answer.exit'));
        PHP;

    /**
     * A stand-in for Psalm's language server. It keeps the arguments it was
     * started with, and whether it saw the withheld variable, in
     * `server-argv.txt`, and each message the gate sent it, a line each, in
     * `server-got.txt`. It asks the gate to answer a request of its own
     * before it answers `initialize`, and tells it something after. For each file opened or changed under
     * `src/` but not `src/Excluded.php`, it publishes a diagnostic for each
     * `// error: Type message` or `// info: Type message` line of the text,
     * with the version it read; it answers every `$/` request with the error
     * the protocol gives one it does not implement. It writes to its error
     * stream as it starts. `server.mode` makes it end at once (`ends`), end
     * once a file is sent, saying it gave up (`ends-on-file`), publish each
     * file with the version before the one it read (`stale`), frame each
     * message with its `Content-Length` alone, as the protocol allows
     * (`bare`), never answer `initialize` (`mute-start`), or never answer a
     * `$/` request (`mute`).
     */
    private const string SERVER = <<<'PHP'
        <?php
        $here = __DIR__;
        $mode = (string) @file_get_contents("$here/server.mode");
        $leaked = getenv('MUTATION_GATE_CONTRACT_TOKEN') !== false ? 'leaked' : 'withheld';
        file_put_contents("$here/server-argv.txt", implode("\n", [...array_slice($argv, 1), $leaked]));
        if ($mode === 'ends') {
            fwrite(STDERR, 'no server here');
            exit(1);
        }
        $type = $mode === 'bare' ? '' : "Content-Type: application/vscode-jsonrpc; charset=utf8\r\n";
        $send = static function (array $message) use ($type): void {
            $json = json_encode(['jsonrpc' => '2.0', ...$message], JSON_UNESCAPED_SLASHES);
            fwrite(STDOUT, sprintf("%sContent-Length: %d\r\n\r\n%s", $type, strlen($json), $json));
            fflush(STDOUT);
        };
        while (($line = fgets(STDIN)) !== false) {
            $length = 0;
            while (trim($line) !== '') {
                $length = preg_match('~^Content-Length: (\d+)~i', $line, $m) === 1 ? (int) $m[1] : $length;
                $line = (string) fgets(STDIN);
            }
            $message = json_decode((string) fread(STDIN, $length), true);
            $method = $message['method'] ?? '';
            $document = $message['params']['textDocument'] ?? [];
            file_put_contents("$here/server-got.txt", sprintf("%s %s %s %s\n", $method === '' ? 'answer' : $method, $message['id'] ?? '-', $document['uri'] ?? '-', $document['version'] ?? '-'), FILE_APPEND);
            if ($method === 'initialize') {
                fwrite(STDERR, 'starting');
                $send(['method' => 'window/logMessage', 'params' => ['type' => 3, 'message' => 'Starting']]);
                $send(['id' => 'ask', 'method' => 'workspace/configuration', 'params' => ['items' => []]]);
                if ($mode !== 'mute-start') {
                    $send(['id' => $message['id'], 'result' => ['capabilities' => []]]);
                }
                $send(['method' => 'telemetry/event', 'params' => ['type' => 3, 'message' => 'running']]);
            }
            if (str_starts_with($method, '$/') && $mode !== 'mute') {
                $send(['id' => $message['id'], 'error' => ['code' => -32601, 'message' => "Method $method is not implemented"]]);
            }
            if ($method === 'textDocument/didOpen' || $method === 'textDocument/didChange') {
                if ($mode === 'ends-on-file') {
                    fwrite(STDERR, 'gave up');
                    exit(1);
                }
                $path = rawurldecode(substr($document['uri'], strlen('file://')));
                $text = $document['text'] ?? $message['params']['contentChanges'][0]['text'];
                preg_match_all('~// (error|info): (\w+) (.*)~', $text, $found, PREG_SET_ORDER);
                $diagnostics = array_map(static fn(array $f): array => [
                    'severity' => $f[1] === 'error' ? 1 : 3,
                    'message' => sprintf('[%s] %s', $f[2], $f[3]),
                    'data' => ['type' => $f[2]],
                ], $found);
                if (str_contains($path, '/src/') && ! str_ends_with($path, '/src/Excluded.php')) {
                    $send(['method' => 'textDocument/publishDiagnostics', 'params' => ['uri' => $document['uri'], 'version' => $document['version'] - ($mode === 'stale' ? 1 : 0), 'diagnostics' => $diagnostics]]);
                }
            }
        }
        PHP;

    /** A project whose `vendor/bin/phpstan` is the stand-in, with a phpstan.neon, saying this version. */
    public static function phpstan(string $version): string
    {
        $project = Scratch::directory();
        Scratch::write($project, 'vendor/bin/phpstan', self::SCRIPT);
        Scratch::write($project, 'vendor/bin/version.txt', $version);
        Scratch::write($project, 'phpstan.neon', "parameters:\n    level: 9\n");

        return $project;
    }

    /**
     * A project whose `vendor/bin/psalm` is the stand-in, and whose
     * `vendor/bin/psalm-language-server` is the server's, saying this version,
     * with a psalm.xml that analyses `src` but `src/Excluded.php`.
     */
    public static function psalm(string $version): string
    {
        $project = Scratch::directory();
        Scratch::write($project, 'vendor/bin/psalm', self::SCRIPT);
        Scratch::write($project, 'vendor/bin/psalm-language-server', self::SERVER);
        Scratch::write($project, 'vendor/bin/version.txt', $version);
        Scratch::write($project, 'psalm.xml', self::PSALM_XML);

        return $project;
    }

    /**
     * A project whose vendor directory holds Mago as its package downloaded it, as the stand-in's
     * executable, saying this version, and listing these files as analysed.
     */
    public static function mago(string $version, string $files): string
    {
        $project = Scratch::directory();
        $bin = sprintf('vendor/carthage-software/mago/composer/bin/%1$s/mago-%1$s-test', $version);
        Scratch::write($project, 'vendor/composer/installed.json', sprintf(
            '{"packages": [{"name": "carthage-software/mago", "version": "%s"}]}',
            $version,
        ));
        Scratch::write($project, sprintf('%s/fake.php', $bin), self::SCRIPT);
        Scratch::write($project, sprintf('%s/mago', $bin), sprintf("#!/bin/sh\nexec %s \"$(dirname \"$0\")/fake.php\" \"$@\"\n", PHP_BINARY));
        chmod(sprintf('%s/%s/mago', $project, $bin), 0o755);
        Scratch::write($project, sprintf('%s/version.txt', $bin), sprintf('mago %s', $version));
        Scratch::write($project, sprintf('%s/files.txt', $bin), $files);
        Scratch::write($project, sprintf('%s/files.exit', $bin), '0');

        return $project;
    }

    /** Where the stand-in keeps what it answers and what it was run with, in a project made above. */
    public static function scripts(string $project): string
    {
        $found = glob(sprintf('%s/vendor/carthage-software/mago/composer/bin/*/mago-*', $project));

        return $found === false || $found === [] ? sprintf('%s/vendor/bin', $project) : $found[0];
    }
}
