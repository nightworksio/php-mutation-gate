<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function dirname;
use function file_put_contents;
use function getcwd;
use function is_dir;
use function is_file;
use function is_object;
use function is_string;
use function method_exists;
use function mkdir;

use PHPUnit\Framework\SkippedWithMessageException;

use function putenv;
use function sprintf;

use Throwable;

use function var_export;

/**
 * The application a stream boots once, under `splitStream.bootOnce`, for a
 * framework whose test case asks for it where this hands it in (Laravel 11
 * and later, whose `TestCase::createApplication()` requires the
 * `bootstrap/app.php` under `Application::inferBasePath()`, which reads
 * `APP_BASE_PATH`). The stream points that variable at a directory whose
 * `bootstrap/app.php` asks this for the application: the first time, it
 * boots the project's own one, every site the boot runs leaving its mutant
 * to a stream that boots in every test; then, for each test, the stream
 * forks, and the test's own process takes the booted application, which
 * the test case's bootstrap leaves as it is, while the stream skips the
 * test it handed over. A test the stream cannot fork in, or an application
 * that does not boot, takes a fresh one, as without the gate.
 *
 * @internal
 */
final class Zygote
{
    /** The variable Laravel reads its base path from. */
    private const string BASE = 'APP_BASE_PATH';

    /** The file Laravel's test case requires under its base path. */
    private const string BOOTSTRAP = '%s/bootstrap/app.php';

    /** The console kernel Laravel's test case bootstraps an application with. */
    private const string KERNEL = 'Illuminate\\Contracts\\Console\\Kernel';

    private const string SHIM = "<?php\n\nreturn \\%s::application(%s);\n";

    private const string HANDED
        = 'The split stream ran this test in a process forked from its application, booted once.';

    private static ?object $booted = null;

    private static bool $failed = false;

    /**
     * Points the application's base path at a directory under this one
     * whose bootstrap asks for the application booted once; false where the
     * project has no `bootstrap/app.php` to boot.
     */
    public static function prepared(string $directory): bool
    {
        $base = $_ENV[self::BASE] ?? $_SERVER[self::BASE] ?? getcwd();
        $real = sprintf(self::BOOTSTRAP, is_string($base) ? $base : '');
        $shim = sprintf(self::BOOTSTRAP, $directory);

        if (! is_file($real)) {
            return false;
        }

        if (! is_dir(dirname($shim))) {
            mkdir(dirname($shim), 0o700, recursive: true);
        }

        file_put_contents($shim, sprintf(self::SHIM, self::class, var_export($real, true)));
        $_ENV[self::BASE] = $directory;
        $_SERVER[self::BASE] = $directory;
        putenv(sprintf('%s=%s', self::BASE, $directory));

        return true;
    }

    /**
     * The application a test case asks for: the one booted once, in the
     * test's own process; a fresh one, as without the gate, where the stream
     * cannot fork or the application does not boot. The stream's own
     * process hands the test over and skips it.
     */
    public static function application(string $bootstrap): mixed
    {
        $booted = self::$failed ? null : (self::$booted ?? self::boot($bootstrap));

        return $booted === null ? require $bootstrap : self::handed($booted, $bootstrap);
    }

    private static function handed(object $booted, string $bootstrap): mixed
    {
        $forked = Stream::forkedTest();

        return match ($forked) {
            null => require $bootstrap,
            true => $booted,
            false => throw new SkippedWithMessageException(self::HANDED),
        };
    }

    private static function boot(string $bootstrap): ?object
    {
        Stream::booting(true);

        try {
            $application = require $bootstrap;

            if (is_object($application) && method_exists($application, 'make')) {
                $kernel = $application->make(self::KERNEL);

                if (is_object($kernel) && method_exists($kernel, 'bootstrap')) {
                    $kernel->bootstrap();
                    self::$booted = $application;
                }
            }
        } catch (Throwable) {
            self::$failed = true;
        } finally {
            Stream::booting(false);
        }

        self::$failed = self::$failed || self::$booted === null;

        return self::$booted;
    }
}
