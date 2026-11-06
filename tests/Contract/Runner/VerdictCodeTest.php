<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Key\VerdictCode;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\RunnerContracts;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * Every file of the gate a mutant's process loaded while a library's run
 * judged money's four mutants, by its path from the gate's root: each PHP
 * process the run starts reads an ini file that prepends a script listing
 * what it loaded as it ends. The run is its own, not the one other contracts
 * share, and its library is made once the variable naming that file's
 * directory is set, since its shell reads the environment as it is made.
 *
 * @return list<string>
 */
function loadedByMutants(string $runner): array
{
    $root = dirname(__DIR__, 3);
    $scratch = Scratch::directory();
    $loaded = sprintf('%s/loaded', $scratch);
    mkdir($loaded);
    file_put_contents(sprintf('%s/list.php', $scratch), sprintf(
        "<?php\nregister_shutdown_function(static fn () => file_put_contents(%s . '/' . getmypid() . '-' . hrtime(true), implode(\"\\n\", get_included_files())));\n",
        var_export($loaded, return: true),
    ));
    file_put_contents(sprintf('%s/list.ini', $scratch), sprintf("auto_prepend_file=%s/list.php\n", $scratch));
    $scanned = getenv('PHP_INI_SCAN_DIR');
    $_ENV['PHP_INI_SCAN_DIR'] = sprintf('%s%s%s', is_string($scanned) ? $scanned : '', PATH_SEPARATOR, $scratch);
    putenv(sprintf('PHP_INI_SCAN_DIR=%s', $_ENV['PHP_INI_SCAN_DIR']));

    try {
        RunnerContracts::money(Library::installed($runner), 'money, listing what each process loaded');
    } finally {
        unset($_ENV['PHP_INI_SCAN_DIR']);
        putenv(is_string($scanned) ? sprintf('PHP_INI_SCAN_DIR=%s', $scanned) : 'PHP_INI_SCAN_DIR');
    }

    $files = [];

    $lists = glob(sprintf('%s/*', $loaded));

    foreach (is_array($lists) ? $lists : [] as $listed) {
        $files = [...$files, ...gateFilesIn((string) file_get_contents($listed), $root)];
    }

    return array_values(array_unique($files));
}

/**
 * The files of a list, one a line, that are the gate's, by their path from its root.
 *
 * @return list<string>
 */
function gateFilesIn(string $list, string $root): array
{
    $files = [];

    foreach (explode("\n", $list) as $file) {
        $real = realpath($file);
        $inside = is_string($real) && str_starts_with($real, sprintf('%s/', $root));
        $files = $inside ? [...$files, mb_substr($real, mb_strlen($root) + 1)] : $files;
    }

    return $files;
}

it('reads every file of the gate a mutant\'s process loads into the key, under each runner', function (string $runner, string $named): void {
    $loaded = loadedByMutants($runner);
    $globs = VerdictCode::of($named);
    $outside = array_values(array_filter(
        $loaded,
        static fn(string $file): bool => (str_starts_with($file, 'src/') || str_starts_with($file, 'bin/'))
            && ! array_any($globs, static fn(Glob $glob): bool => $glob->matches(Path::of($file))),
    ));

    expect($loaded)->not->toBe([])
        ->and($outside)->toBe([]);
})->with([
    'pest' => ['pest', 'pest'],
    'pest patched' => ['pest patched', 'pest'],
    'infection' => ['infection', 'infection'],
    'phpunit' => ['phpunit', 'phpunit'],
    'phpspec' => ['phpspec', 'phpspec'],
    'codeception' => ['codeception', 'codeception'],
    'testo' => ['testo', 'testo'],
]);
