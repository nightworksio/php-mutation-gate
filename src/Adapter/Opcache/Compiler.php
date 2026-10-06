<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Opcache;

use function array_chunk;
use function array_filter;
use function array_intersect_key;
use function array_keys;
use function array_map;
use function array_merge;
use function array_replace;
use function array_values;
use function ceil;
use function count;
use function dirname;
use function explode;
use function file_put_contents;
use function getmypid;
use function is_dir;
use function max;
use function mb_str_split;
use function mb_strlen;
use function min;
use function mkdir;

use NightWorksIO\MutationGate\Core\File\Contents;

use function realpath;
use function rmdir;
use function sprintf;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

use function trim;
use function unlink;
use function usleep;

/**
 * Programs compiled by opcache in a child `php` that reads no php.ini, so no
 * setting of the project's can run code in it or change what the optimizer
 * does, with each program's optimized opcodes dumped (ADR-0013, decision
 * 10). Each program is written to a directory of its own, in one for the
 * process, whose calls come one at a time, and compiled there, many to a
 * child and children side by side, and all of it is taken away once they
 * are compiled. Opcache caches no file changed in the last two seconds
 * unless told to, and these were all just written. A program that fails in
 * a child shared with others is compiled again in a child of its own: PHP
 * declares a program's functions as it compiles it, so a second program
 * declaring one of them fails beside the first, and a child that stops on a
 * compile error fails every program it holds.
 */
final readonly class Compiler
{
    /** The programs one child compiles, few enough that opcache's memory never fills. */
    private const int PER_CHILD = 100;

    /** How long to wait between reads of the children, in microseconds. */
    private const int POLL = 1000;

    /** Each program's name in its directory. */
    private const string PROGRAM = 'program.php';

    /** What the child writes to the dump before each program's opcodes: a control character, which a dump escapes. */
    private const string BETWEEN = "\x1Emutation-gate\x1E";

    private const string COMPILED = '1';

    private const string NO_OPCACHE = 'U';

    /**
     * What the child runs with besides PHP's own defaults, which `-n` leaves it: opcache on the command line, its
     * optimized opcodes dumped, every file cached however new, and no error shown among the answers. PHP logs no
     * error by default.
     */
    private const array SETTINGS = [
        'opcache.enable_cli=1',
        'opcache.opt_debug_level=0x20000',
        'opcache.file_update_protection=0',
        'display_errors=0',
    ];

    /** The child's script: for each file, the marker on the dump's stream, then whether it compiled. */
    private const string SCRIPT = <<<'PHP'
        $files = array_slice($argv, 2);
        if (! function_exists('opcache_compile_file')) {
            echo str_repeat('U', count($files));
            exit;
        }
        foreach ($files as $file) {
            fwrite(STDERR, $argv[1]);
            try {
                echo opcache_compile_file($file) ? '1' : '0';
            } catch (Throwable) {
                echo '0';
            }
        }
        PHP;

    /**
     * @param string      $directory where each process writes its programs, in a directory of its own the gate owns
     * @param int<1, max>  $parallel how many children compile side by side
     * @param list<string> $besides  settings the children take after the gate's own
     */
    public function __construct(
        private string $binary,
        private string $directory,
        private float $seconds,
        private int $parallel,
        private array $besides = [],
    ) {
    }

    /**
     * Each program's opcodes, or why there are none, by its key.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, Contents>             $programs
     * @return array<TKey, Opcodes|Uncompiled>
     */
    public function compiled(array $programs): array
    {
        $directory = sprintf('%s/%d', $this->directory, getmypid());
        $paths = $this->written($programs, $directory);
        $each = max(1, min(self::PER_CHILD, (int) ceil(count($paths) / $this->parallel)));
        $shared = $this->waves(array_chunk($paths, $each, preserve_keys: true));
        $failed = array_filter($shared, static fn(Opcodes|Uncompiled $one): bool => $one === Uncompiled::Failed);
        $alone = $each > 1 ? array_chunk(array_intersect_key($paths, $failed), 1, preserve_keys: true) : [];
        $compiled = array_replace($shared, $this->waves($alone));

        $this->removed($paths, $directory);

        return $compiled;
    }

    /**
     * These chunks of programs, each compiled in a child of its own, as many
     * children side by side as may be.
     *
     * @template TKey of array-key
     *
     * @param  list<array<TKey, string>>       $chunks
     * @return array<TKey, Opcodes|Uncompiled>
     */
    private function waves(array $chunks): array
    {
        $compiled = [];

        foreach (array_chunk($chunks, $this->parallel) as $wave) {
            $children = [];

            foreach ($wave as $chunk) {
                $children[] = $this->started($chunk);
            }

            $this->drained($children);

            foreach ($wave as $index => $chunk) {
                $compiled += $this->answered($children[$index], $chunk);
            }
        }

        return $compiled;
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, Contents> $programs
     * @return array<TKey, string>   each program's path, with no link in it, as opcache names it
     */
    private function written(array $programs, string $directory): array
    {
        $paths = [];
        $index = 0;

        foreach ($programs as $key => $program) {
            $own = sprintf('%s/%d', $directory, $index++);
            mkdir($own, recursive: true);
            $path = sprintf('%s/%s', $own, self::PROGRAM);
            file_put_contents($path, $program->text());
            $paths[$key] = (string) realpath($path);
        }

        return $paths;
    }

    /**
     * The programs taken away again, with the directory each was written to
     * and the one they were written in.
     *
     * @param array<array-key, string> $paths
     */
    private function removed(array $paths, string $directory): void
    {
        foreach ($paths as $path) {
            unlink($path);
            rmdir(dirname($path));
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }

    /**
     * Every child read from as it runs, until all have ended or run out of
     * time, so that none waits on a full pipe while another is waited for.
     *
     * @param list<Process|Uncompiled> $children
     */
    private function drained(array $children): void
    {
        $running = array_filter($children, static fn(Process|Uncompiled $child): bool => $child instanceof Process);

        while ($running !== []) {
            $left = [];

            foreach ($running as $child) {
                $left = $this->stillRunning($child) ? [...$left, $child] : $left;
            }

            $running = $left;
            usleep(self::POLL);
        }
    }

    /** Whether a child runs on within its time; one past it is stopped, and fails. */
    private function stillRunning(Process $child): bool
    {
        try {
            $child->checkTimeout();
        } catch (ExceptionInterface) {
            return false;
        }

        return $child->isRunning();
    }

    /**
     * A child started on these programs, or that it could not be: the
     * system could not start it, or it was given no time.
     *
     * @param array<array-key, string> $paths
     */
    private function started(array $paths): Process|Uncompiled
    {
        $options = array_map(
            static fn(string $setting): array => ['-d', $setting],
            [...self::SETTINGS, ...$this->besides],
        );
        try {
            $process = new Process(
                [
                    $this->binary,
                    '-n',
                    ...array_merge(...$options),
                    '-r',
                    self::SCRIPT,
                    self::BETWEEN,
                    ...array_values($paths),
                ],
                timeout: $this->seconds,
            );
            $process->start();
        } catch (ExceptionInterface) {
            return Uncompiled::Failed;
        }

        return $process;
    }

    /**
     * What a child that has ended compiled of these programs; each of them
     * failed, where it did not end well or did not answer for each.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, string>             $paths
     * @return array<TKey, Opcodes|Uncompiled>
     */
    private function answered(Process|Uncompiled $child, array $paths): array
    {
        if ($child instanceof Uncompiled) {
            return $this->failed($paths);
        }

        $answers = trim($child->getOutput());

        return $child->getExitCode() === 0 && mb_strlen($answers) === count($paths)
            ? $this->read($paths, mb_str_split($answers), explode(self::BETWEEN, $child->getErrorOutput()))
            : $this->failed($paths);
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, string>     $paths
     * @return array<TKey, Uncompiled> each of these programs failed
     */
    private function failed(array $paths): array
    {
        return array_map(static fn(): Uncompiled => Uncompiled::Failed, $paths);
    }

    /**
     * Each program as the child answered for it: a program it compiled has
     * the opcodes it dumped of it, where its dump splits into one section for
     * each program, and fails where it does not, since a section can then be
     * another program's.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, string>             $paths
     * @param  list<string>                    $answers whether the child compiled each program
     * @param  list<string>                    $dumps   what it dumped before the first program, then of each
     * @return array<TKey, Opcodes|Uncompiled>
     */
    private function read(array $paths, array $answers, array $dumps): array
    {
        $read = [];
        $whole = count($dumps) === count($paths) + 1;

        foreach (array_keys($paths) as $index => $key) {
            $read[$key] = match (true) {
                $answers[$index] === self::NO_OPCACHE => Uncompiled::NoOpcache,
                $answers[$index] !== self::COMPILED || ! $whole => Uncompiled::Failed,
                default => $this->opcodesOf($dumps[$index + 1], $paths[$key]),
            };
        }

        return $read;
    }

    /** The opcodes of a program compiled at a path, from its section of the dump; none where opcache dumped none. */
    private function opcodesOf(string $dump, string $path): Opcodes|Uncompiled
    {
        return trim($dump) === '' ? Uncompiled::NoOpcache : Opcodes::dumped($dump, $path);
    }
}
