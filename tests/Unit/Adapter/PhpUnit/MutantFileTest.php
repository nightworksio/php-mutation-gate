<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\MutantFile;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Variable;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Source;

afterEach(function (): void {
    $handle = fopen(__FILE__, 'rb');

    if (is_resource($handle) && stream_get_meta_data($handle)['wrapper_type'] === 'user-space') {
        stream_wrapper_restore('file');
    }

    putenv(Variable::Mutant->value);
    Scratch::sweep();
});

/**
 * A directory with a file the wrapper serves and its mutant, each returning
 * which one it is, the wrapper standing in for PHP's own.
 */
function servedIn(): string
{
    $directory = (string) realpath(Scratch::directory());
    Scratch::write($directory, 'Original.php', "<?php\n\nreturn 'original';\n");
    Scratch::write($directory, 'mutant.php', "<?php\n\nreturn 'mutant';\n");
    putenv(sprintf('%s=%s/Original.php%s%s/mutant.php', Variable::Mutant->value, $directory, MutantFile::PAIR, $directory));
    MutantFile::serveFromEnvironment();

    return $directory;
}

it('serves the mutant where PHP includes the file it serves, by any spelling of its path, and nothing else', function (): void {
    $directory = servedIn();

    expect(require sprintf('%s/Original.php', $directory))->toBe('mutant')
        ->and(require sprintf('%s/../%s/Original.php', $directory, basename($directory)))->toBe('mutant')
        ->and(file_get_contents(sprintf('%s/Original.php', $directory)))->toBe("<?php\n\nreturn 'original';\n");
});

it('stands in for nothing where no mutant is named, or it is named without its pair', function (): void {
    $directory = (string) realpath(Scratch::directory());
    Scratch::write($directory, 'Original.php', "<?php\n\nreturn 'original';\n");
    putenv(sprintf('%s=%s/Original.php', Variable::Mutant->value, $directory));
    MutantFile::serveFromEnvironment();

    expect(require sprintf('%s/Original.php', $directory))->toBe('original');
});

it('reads, writes, seeks, locks, truncates and states an open file as PHP\'s own wrapper does', function (): void {
    $directory = servedIn();
    $file = sprintf('%s/notes.txt', $directory);
    $handle = fopen($file, 'w+b');
    assert(is_resource($handle));

    fwrite($handle, 'written');
    $appended = file_put_contents(sprintf('%s/log.txt', $directory), '!', FILE_APPEND | LOCK_EX);
    fflush($handle);
    $locked = flock($handle, LOCK_EX);
    $unlocked = flock($handle, LOCK_UN);
    rewind($handle);
    $text = fread($handle, 100);
    $end = feof($handle);
    fseek($handle, 2);
    $at = ftell($handle);
    ftruncate($handle, 3);
    $size = fstat($handle);
    $read = [$text, $end, $at, is_array($size) ? $size['size'] : -1];
    fclose($handle);
    $again = fopen($file, 'rb');
    assert(is_resource($again));

    expect($appended)->toBe(1)
        ->and($locked)->toBeTrue()
        ->and($unlocked)->toBeTrue()
        ->and($read)->toBe(['written', true, 2, 3])
        ->and(stream_set_blocking($again, enable: false))->toBeFalse();
});

it('touches, stats, renames, removes and lists files and directories as PHP\'s own wrapper does', function (): void {
    $directory = servedIn();
    $file = sprintf('%s/touched.txt', $directory);

    touch($file, 1_000_000, 1_000_000);
    $touchedAt = filemtime($file);
    chmod($file, 0o600);
    $mode = fileperms($file) & 0o777;
    $owned = chown($file, (int) fileowner($file)) && chgrp($file, (int) filegroup($file));
    rename($file, sprintf('%s/renamed.txt', $directory));
    mkdir(sprintf('%s/made/deeper', $directory), 0o755, recursive: true);
    $listing = opendir($directory);
    assert(is_resource($listing));
    $first = readdir($listing);
    rewinddir($listing);
    $again = readdir($listing);
    closedir($listing);
    $files = scandir($directory);

    expect($touchedAt)->toBe(1_000_000)
        ->and($mode)->toBe(0o600)
        ->and($owned)->toBeTrue()
        ->and(file_exists($file))->toBeFalse()
        ->and(is_link($file))->toBeFalse()
        ->and($first)->toBe($again)
        ->and($files)->toContain('renamed.txt', 'made')
        ->and(rmdir(sprintf('%s/made/deeper', $directory)))->toBeTrue()
        ->and(unlink(sprintf('%s/renamed.txt', $directory)))->toBeTrue()
        ->and(is_dir(sprintf('%s/made', $directory)))->toBeTrue();
});

it('hands an open file itself to stream_select', function (): void {
    $directory = servedIn();
    $handle = fopen(sprintf('%s/Original.php', $directory), 'rb');
    assert(is_resource($handle));
    $read = [$handle];
    $none = null;

    expect(stream_select($read, $none, $none, 0))->toBe(1);
});

it('writes the override\'s line that registers it, naming its own file', function (): void {
    expect(MutantFile::registering())->toBe(sprintf(
        "require %s;\n\\%s::serveFromEnvironment();\n",
        var_export((string) realpath(sprintf('%s/src/Adapter/PhpUnit/MutantFile.php', dirname(__DIR__, 4))), return: true),
        MutantFile::class,
    ));
});

it('names no other class of the package, since PHP loads it before the autoloader', function (): void {
    $names = Source::at('src/Adapter/PhpUnit/MutantFile.php')->names();

    expect(array_values(array_filter($names, static fn(string $name): bool => str_starts_with($name, 'NightWorksIO\\'))))
        ->toBe([]);
});
