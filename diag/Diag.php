<?php

declare(strict_types=1);

// Throwaway diagnostics for a draft pull request that is never merged. In a
// mutant's own process for one of the watched files, it logs what PHP had
// loaded when the plugin booted, the arguments, and every test the process
// ran, failed or errored, to /tmp/mutation-gate-diag, one file per mutated copy.

use PHPUnit\Event\Facade;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;

final class Diag
{
    private const array WATCHED = [
        'src/Adapter/Pest/Command.php',
        'src/Adapter/Pest/Invocation.php',
        'src/Adapter/Pest/Order/Reordering.php',
    ];

    private const string DIR = '/tmp/mutation-gate-diag';

    /** @var list<string> */
    public static array $ran = [];

    /** @var list<string> */
    public static array $failed = [];

    private static string $file = '';

    private static int $boots = 0;

    private static bool $ended = false;

    /** @param list<string> $loaded */
    public static function boot(array $loaded): void
    {
        $original = getenv('PEST_MUTATION_TESTING');
        $mutated = getenv('PEST_MUTATION_FILE');

        if (! is_string($original) || ! is_string($mutated) || $original === '' || $mutated === '') {
            return;
        }

        $relative = self::relative($original);

        if (! in_array($relative, self::WATCHED, true)) {
            return;
        }

        self::$boots++;
        @mkdir(self::DIR, 0777, true);
        self::$file = sprintf('%s/%s.jsonl', self::DIR, hash('sha256', $mutated));
        $real = realpath($original);
        self::write([
            'event' => 'boot',
            'boot' => self::$boots,
            'pid' => getmypid(),
            'original' => $relative,
            'mutated' => $mutated,
            'copy' => is_file($mutated) ? (string) file_get_contents($mutated) : null,
            'argv' => $_SERVER['argv'] ?? [],
            'env' => self::environment(),
            'originalLoaded' => in_array($real, $loaded, true),
            'loaded' => array_map(self::relative(...), $loaded),
            'trace' => new Exception()->getTraceAsString(),
        ]);

        if (self::$boots > 1) {
            return;
        }

        try {
            Facade::instance()->registerSubscribers(
                new class implements FinishedSubscriber {
                    public function notify(Finished $event): void
                    {
                        Diag::$ran[] = $event->test()->id();
                    }
                },
                new class implements FailedSubscriber {
                    public function notify(Failed $event): void
                    {
                        Diag::$failed[] = 'failed ' . $event->test()->id();
                    }
                },
                new class implements ErroredSubscriber {
                    public function notify(Errored $event): void
                    {
                        Diag::$failed[] = 'errored ' . $event->test()->id() . ': ' . $event->throwable()->message();
                    }
                },
                new class implements ExecutionFinishedSubscriber {
                    public function notify(ExecutionFinished $event): void
                    {
                        Diag::end('execution-finished');
                    }
                },
            );
        } catch (Throwable $failure) {
            self::write(['event' => 'subscribe-failed', 'why' => $failure::class]);
        }

        register_shutdown_function(static function (): void {
            Diag::end('shutdown');
        });
    }

    /** @param list<string> $handled */
    public static function handled(array $handled): void
    {
        if (self::$file !== '') {
            self::write(['event' => 'handled', 'pid' => getmypid(), 'arguments' => $handled]);
        }
    }

    public static function end(string $how): void
    {
        if (self::$file === '' || (self::$ended && $how === 'shutdown')) {
            return;
        }

        self::$ended = true;
        $original = getenv('PEST_MUTATION_TESTING');
        self::write([
            'event' => 'end',
            'how' => $how,
            'pid' => getmypid(),
            'ran' => count(self::$ran),
            'tests' => self::$ran,
            'failed' => self::$failed,
            'originalLoadedAtEnd' => is_string($original) && in_array(realpath($original), get_included_files(), true),
        ]);
    }

    /** @return array<string, string> */
    private static function environment(): array
    {
        $kept = [];

        foreach (getenv() as $name => $value) {
            if (preg_match('/\A(MUTATION_GATE_|PEST_|PARATEST|TEST_TOKEN|UNIQUE_TEST_TOKEN|LARAVEL_PARALLEL|PHP_INI_SCAN_DIR)/', $name) === 1) {
                $kept[$name] = $value;
            }
        }

        return $kept;
    }

    private static function relative(string $path): string
    {
        $root = getcwd() . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    /** @param array<string, mixed> $record */
    private static function write(array $record): void
    {
        file_put_contents(self::$file, json_encode($record, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }
}
