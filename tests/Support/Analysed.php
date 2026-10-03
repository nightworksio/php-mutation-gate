<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_map;
use function basename;

use NightWorksIO\MutationGate\Core\Format\Node;

use const PHP_BINARY;

use RuntimeException;

use function sort;
use function sprintf;

use Symfony\Component\Process\Process;

/**
 * What PHPStan reports over a throwaway tree that holds only the files given,
 * analysed with only the services given, as each message names its file and
 * line. The tree's paths sit under `src`, as the package's own source does.
 */
final readonly class Analysed
{
    /** The tree's configuration, which its paths and services fill in. */
    private const string CONFIGURATION = <<<'NEON'
        parameters:
            level: 0
            tmpDir: cache
            paths:
                - src
        services:
        %s
        NEON;

    /**
     * Each message, as `file:line message`, the file by its name alone, in order.
     *
     * @param array<string, string> $files    the code of each file, by its path under `src`
     * @param string                $services the services, as NEON writes them under `services:`
     * @param list<string>          $only     files under `src` to analyse alone, where the run is not over every configured path
     *
     * @return list<string>
     */
    public static function messages(array $files, string $services, array $only = []): array
    {
        $root = Scratch::directory();

        foreach ($files as $path => $code) {
            Scratch::write($root, sprintf('src/%s', $path), $code);
        }

        Scratch::write($root, 'phpstan.neon', sprintf(self::CONFIGURATION, $services));
        $run = new Process(
            [
                PHP_BINARY,
                Tree::at('vendor/bin/phpstan'),
                'analyse',
                '--error-format=json',
                '--no-progress',
                '--memory-limit=1G',
                sprintf('--configuration=%s/phpstan.neon', $root),
                ...array_map(static fn(string $path): string => sprintf('%s/src/%s', $root, $path), $only),
            ],
            $root,
            timeout: null,
        );
        $run->run();
        $reported = Node::decode($run->getOutput())->field('files');

        return $reported->isPresent() ? self::read($reported) : throw new RuntimeException(sprintf(
            "PHPStan reported nothing readable over the tree. It said:\n%s",
            $run->getErrorOutput(),
        ));
    }

    /** @return list<string> */
    private static function read(Node $reported): array
    {
        $messages = [];

        foreach ($reported->entries() as $file => $inTheFile) {
            foreach ($inTheFile->field('messages')->items() as $message) {
                $messages[] = sprintf(
                    '%s:%d %s',
                    basename(sprintf('%s', $file)),
                    $message->field('line')->integer(),
                    $message->field('message')->text(),
                );
            }
        }

        sort($messages);

        return $messages;
    }
}
