<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\MutantFile;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Source;

afterEach(function (): void {
    $handle = fopen(__FILE__, 'rb');

    if (is_resource($handle) && stream_get_meta_data($handle)['wrapper_type'] === 'user-space') {
        stream_wrapper_restore('file');
    }

    Scratch::sweep();
});

/**
 * A directory with a file the wrapper serves and its mutant, each returning
 * which one it is, and the guard file, the wrapper standing in for PHP's own.
 */
function servedIn(): string
{
    $directory = (string) realpath(Scratch::directory());
    Scratch::write($directory, 'Original.php', "<?php\n\nreturn 'original';\n");
    Scratch::write($directory, 'mutant.php', "<?php\n\nreturn 'mutant';\n");
    Scratch::write($directory, 'guard.txt', '');
    MutantFile::serve(
        sprintf('%s/Original.php', $directory),
        sprintf('%s/mutant.php', $directory),
        sprintf('%s/guard.txt', $directory),
    );

    return $directory;
}

/**
 * What an operation answers and every warning it raises.
 *
 * @return array{mixed, list<string>}
 */
function warnedBy(Closure $operation): array
{
    $warnings = [];
    set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });

    try {
        $answer = $operation();
    } finally {
        restore_error_handler();
    }

    return [$answer, $warnings];
}

/**
 * What an operation answered and raised, with a directory in a warning written the same wherever it is.
 *
 * @param  array{mixed, list<string>} $said
 * @return array{mixed, list<string>}
 */
function inPlace(array $said, string $directory): array
{
    return [
        $said[0],
        array_map(static fn(string $warning): string => str_replace($directory, '<directory>', $warning), $said[1]),
    ];
}

/**
 * What an operation on a directory's files answers and raises, first on PHP's own wrapper and then on the gate's,
 * with each directory in a warning written the same.
 *
 * @param  Closure(string): mixed                                     $operation
 * @return array{array{mixed, list<string>}, array{mixed, list<string>}}
 */
function nativeThenServed(Closure $operation): array
{
    $native = (string) realpath(Scratch::directory());
    $served = servedIn();
    stream_wrapper_restore('file');
    $natively = inPlace(warnedBy(static fn(): mixed => $operation($native)), $native);
    MutantFile::serve(
        sprintf('%s/Original.php', $served),
        sprintf('%s/mutant.php', $served),
        sprintf('%s/guard.txt', $served),
    );

    return [$natively, inPlace(warnedBy(static fn(): mixed => $operation($served)), $served)];
}

it('serves the mutant where PHP includes the file it serves, by any path that names it, and nothing else', function (): void {
    $directory = servedIn();
    symlink(sprintf('%s/Original.php', $directory), sprintf('%s/Linked.php', $directory));
    mkdir(sprintf('%s/through', $directory));
    symlink($directory, sprintf('%s/through/here', $directory));

    expect(require sprintf('%s/Original.php', $directory))->toBe('mutant')
        ->and(require sprintf('%s/../%s/Original.php', $directory, basename($directory)))->toBe('mutant')
        ->and(require sprintf('%s/Linked.php', $directory))->toBe('mutant')
        ->and(require sprintf('%s/through/here/Original.php', $directory))->toBe('mutant')
        ->and(file_get_contents(sprintf('%s/Original.php', $directory)))->toBe("<?php\n\nreturn 'original';\n");
});

it('serves the mutant of a file named through a link', function (): void {
    $directory = (string) realpath(Scratch::directory());
    Scratch::write($directory, 'real/Original.php', "<?php\n\nreturn 'original';\n");
    Scratch::write($directory, 'mutant.php', "<?php\n\nreturn 'mutant';\n");
    symlink(sprintf('%s/real', $directory), sprintf('%s/named', $directory));
    MutantFile::serve(
        sprintf('%s/named/Original.php', $directory),
        sprintf('%s/mutant.php', $directory),
        sprintf('%s/guard.txt', $directory),
    );

    expect(require sprintf('%s/real/Original.php', $directory))->toBe('mutant');
});

it('serves the mutant by another case of its name where the filesystem ignores case', function (): void {
    $directory = servedIn();

    expect(require sprintf('%s/ORIGINAL.php', $directory))->toBe('mutant');
})->skip(
    ! is_file(sprintf('%s/COMPOSER.JSON', dirname(__DIR__, 4))),
    'only a filesystem that ignores case names one file by two cases',
);

it('says in the guard file each time it serves the mutant', function (): void {
    $directory = servedIn();
    require sprintf('%s/Original.php', $directory);
    require sprintf('%s/Original.php', $directory);

    expect(file_get_contents(sprintf('%s/guard.txt', $directory)))->toBe("served\nserved\n");
});

it('opens a file that is not there for writing, and a directory only where it is one', function (): void {
    $directory = servedIn();
    [$opened, $warned] = warnedBy(static fn(): array => [
        is_resource(fopen(sprintf('%s/new.txt', $directory), 'xb')),
        opendir(sprintf('%s/none', $directory)),
    ]);

    expect($opened)->toBe([true, false])
        ->and($warned)->toHaveCount(1);
});

it('says nothing in the guard file where it served nothing', function (): void {
    $directory = servedIn();
    file_get_contents(sprintf('%s/Original.php', $directory));

    expect(file_get_contents(sprintf('%s/guard.txt', $directory)))->toBe('');
});

it('stands in for nothing where the file it would serve is not there, or no mutant or guard is named', function (string $original, string $mutated, string $guard): void {
    $directory = (string) realpath(Scratch::directory());
    Scratch::write($directory, 'Original.php', "<?php\n\nreturn 'original';\n");
    Scratch::write($directory, 'mutant.php', "<?php\n\nreturn 'mutant';\n");
    MutantFile::serve(
        $original === '' ? '' : sprintf('%s/%s', $directory, $original),
        $mutated === '' ? '' : sprintf('%s/%s', $directory, $mutated),
        $guard === '' ? '' : sprintf('%s/%s', $directory, $guard),
    );
    $handle = fopen(__FILE__, 'rb');

    expect(require sprintf('%s/Original.php', $directory))->toBe('original')
        ->and(is_resource($handle) ? stream_get_meta_data($handle)['wrapper_type'] : '')->toBe('plainfile');
})->with([
    'a file that is not there' => ['Gone.php', 'mutant.php', 'guard.txt'],
    'no mutant' => ['Original.php', '', 'guard.txt'],
    'no guard' => ['Original.php', 'mutant.php', ''],
]);

it('reads, writes, seeks, locks, truncates and states an open file as PHP\'s own wrapper does', function (): void {
    [$native, $served] = nativeThenServed(static function (string $directory): array {
        $file = sprintf('%s/notes.txt', $directory);
        $handle = fopen($file, 'w+b');
        assert(is_resource($handle));
        fwrite($handle, 'written');
        $appended = file_put_contents(sprintf('%s/log.txt', $directory), '!', FILE_APPEND | LOCK_EX);
        fflush($handle);
        $locked = [flock($handle, LOCK_EX), flock($handle, LOCK_UN)];
        rewind($handle);
        $text = fread($handle, 100);
        fseek($handle, 2);
        $at = [ftell($handle), feof($handle)];
        ftruncate($handle, 3);
        $size = fstat($handle);
        fclose($handle);
        unlink($file);
        unlink(sprintf('%s/log.txt', $directory));

        return [$appended, $locked, $text, $at, is_array($size) ? $size['size'] : -1];
    });

    expect($served)->toBe($native)
        ->and($native)->toBe([[1, [true, true], 'written', [2, false], 3], []]);
});

it('reaches a file\'s end only once a read gets nothing, as a loop over its lines expects', function (): void {
    [$native, $served] = nativeThenServed(static function (string $directory): array {
        $file = sprintf('%s/lines.txt', $directory);
        file_put_contents($file, "a\nb\n");
        $handle = fopen($file, 'rb');
        assert(is_resource($handle));
        $lines = [];

        while (! feof($handle)) {
            $lines[] = fgets($handle);
        }

        rewind($handle);
        fread($handle, 4);
        $atFour = feof($handle);
        fclose($handle);
        $object = [];

        try {
            foreach (new SplFileObject($file) as $line) {
                $object[] = $line;
            }
        } catch (LogicException|RuntimeException) {
            $object = ['unreadable'];
        }

        unlink($file);

        return [$lines, $atFour, $object];
    });

    expect($served)->toBe($native)
        ->and($native[0])->toBe([[ "a\n", "b\n", false], false, ["a\n", "b\n", '']]);
});

it('says a file is not yet at its end after a read that asked for more than was left, where PHP\'s own wrapper says it is', function (): void {
    [$native, $served] = nativeThenServed(static function (string $directory): bool {
        $file = sprintf('%s/short.txt', $directory);
        file_put_contents($file, "a\nb\n");
        $handle = fopen($file, 'rb');
        assert(is_resource($handle));
        fread($handle, 100);
        $end = feof($handle);
        fclose($handle);
        unlink($file);

        return $end;
    });

    expect([$native[0], $served[0]])->toBe([true, false]);
});

it('touches, stats, renames, removes and lists files and directories as PHP\'s own wrapper does', function (): void {
    [$native, $served] = nativeThenServed(static function (string $directory): array {
        $file = sprintf('%s/touched.txt', $directory);
        touch($file, 1_000_000, 2_000_000);
        $times = [filemtime($file), fileatime($file)];
        touch($file);
        $touchedNow = filemtime($file) !== 1_000_000;
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
        $removed = [
            rmdir(sprintf('%s/made/deeper', $directory)),
            rmdir(sprintf('%s/made', $directory)),
            unlink(sprintf('%s/renamed.txt', $directory)),
        ];

        return [
            $times,
            $touchedNow,
            $mode,
            $owned,
            file_exists($file),
            $first === $again,
            in_array('renamed.txt', is_array($files) ? $files : [], strict: true),
            $removed,
        ];
    });

    expect($served)->toBe($native)
        ->and($native[0])->toBe([[1_000_000, 2_000_000], true, 0o600, true, false, true, true, [true, true, true]]);
});

it('states a link itself or what it points at, and a dangling link, without a warning PHP\'s own wrapper does not raise', function (): void {
    [$native, $served] = nativeThenServed(static function (string $directory): array {
        file_put_contents(sprintf('%s/target.txt', $directory), 'here');
        symlink(sprintf('%s/target.txt', $directory), sprintf('%s/link', $directory));
        symlink(sprintf('%s/gone.txt', $directory), sprintf('%s/dangling', $directory));
        $stated = [
            is_link(sprintf('%s/link', $directory)),
            is_file(sprintf('%s/link', $directory)),
            filesize(sprintf('%s/link', $directory)),
            file_exists(sprintf('%s/dangling', $directory)),
            is_file(sprintf('%s/dangling', $directory)),
            is_link(sprintf('%s/dangling', $directory)),
        ];
        unlink(sprintf('%s/link', $directory));
        unlink(sprintf('%s/dangling', $directory));
        unlink(sprintf('%s/target.txt', $directory));

        return $stated;
    });

    expect($served)->toBe($native)
        ->and($native)->toBe([[true, true, 4, false, false, true], []]);
});

it('raises only PHP\'s own warning for a file that is not there', function (): void {
    [$native, $served] = nativeThenServed(static function (string $directory): array {
        $gone = sprintf('%s/gone.txt', $directory);

        return [filesize($gone), fileperms($gone), fopen($gone, 'rb'), file_get_contents($gone)];
    });

    expect($served[0])->toBe($native[0])
        ->and($served[1])->toHaveCount(count($native[1]))
        ->and(array_slice($served[1], 0, 2))->toBe(array_slice($native[1], 0, 2));
});

it('opens a file by the include path', function (): void {
    $directory = servedIn();
    Scratch::write($directory, 'on/path.txt', 'found');
    $before = set_include_path(sprintf('%s/on', $directory));
    $handle = fopen('path.txt', 'rb', use_include_path: true);
    set_include_path(is_string($before) ? $before : '.');

    expect(is_resource($handle) ? fread($handle, 10) : false)->toBe('found');
});

it('sets whether an open file blocks, and nothing a file on disk does not have', function (): void {
    [$native, $served] = nativeThenServed(static function (string $directory): array {
        file_put_contents(sprintf('%s/blocking.txt', $directory), '');
        $handle = fopen(sprintf('%s/blocking.txt', $directory), 'rb');
        assert(is_resource($handle));

        return [
            stream_set_blocking($handle, enable: false),
            stream_set_blocking($handle, enable: true),
            stream_set_timeout($handle, 5),
            stream_set_write_buffer($handle, 100),
        ];
    });

    expect($served)->toBe($native)
        ->and($native[0])->toBe([true, true, false, -1]);
});

it('hands an open file itself to stream_select', function (): void {
    $directory = servedIn();
    $handle = fopen(sprintf('%s/Original.php', $directory), 'rb');
    assert(is_resource($handle));
    $read = [$handle];
    $none = null;

    expect(stream_select($read, $none, $none, 0))->toBe(1);
});

it('reads a byte where asked for none, and truncates to nothing where asked for less, as PHP never asks', function (): void {
    $directory = servedIn();
    Scratch::write($directory, 'file.txt', 'abc');
    $file = new MutantFile();
    $file->stream_open(sprintf('%s/file.txt', $directory), 'r+b', 0);

    expect($file->stream_read(0))->toBe('a')
        ->and($file->stream_truncate(-1))->toBeTrue()
        ->and(filesize(sprintf('%s/file.txt', $directory)))->toBe(0);
});

it('answers for nothing it has not opened', function (): void {
    servedIn();
    $file = new MutantFile();

    expect([
        $file->stream_read(1),
        $file->stream_write('x'),
        $file->stream_stat(),
        $file->stream_seek(0, SEEK_SET),
        $file->stream_tell(),
        $file->stream_flush(),
        $file->stream_lock(LOCK_EX),
        $file->stream_truncate(0),
        $file->stream_set_option(STREAM_OPTION_BLOCKING, 1),
        $file->stream_cast(),
        $file->dir_readdir(),
        $file->dir_rewinddir(),
    ])->toBe([false, false, false, false, false, false, false, false, false, false, false, false])
        ->and($file->dir_closedir())->toBeTrue();
});

it('closes the directory it opened', function (): void {
    $directory = servedIn();
    $file = new MutantFile();
    $file->dir_opendir($directory);
    $handle = new ReflectionProperty(MutantFile::class, 'handle')->getValue($file);
    $file->dir_closedir();

    expect(is_resource($handle))->toBeFalse();
});

it('changes the owner and the group by name as PHP\'s own wrapper does', function (): void {
    [$native, $served] = nativeThenServed(static function (string $directory): array {
        $file = sprintf('%s/owned.txt', $directory);
        file_put_contents($file, '');
        $changed = [chown($file, 'no-such-user-of-the-gate'), chgrp($file, 'no-such-group-of-the-gate')];
        unlink($file);

        return $changed;
    });

    expect($served[0])->toBe($native[0])
        ->and($native[0])->toBe([false, false]);
});

it('writes the override\'s lines that register it, naming its own file and the variables it reads', function (): void {
    expect(MutantFile::registering('ORIGINAL', 'MUTATED', 'GUARD'))->toBe(sprintf(
        "require %s;\n\\%s::serve((string) \\getenv('ORIGINAL'), (string) \\getenv('MUTATED'), (string) \\getenv('GUARD'));\n",
        var_export((string) realpath(sprintf('%s/src/Adapter/PhpUnit/MutantFile.php', dirname(__DIR__, 4))), return: true),
        MutantFile::class,
    ));
});

it('names no other class of the package, since PHP loads it before the autoloader', function (): void {
    $names = Source::at('src/Adapter/PhpUnit/MutantFile.php')->names();

    expect(array_values(array_filter($names, static fn(string $name): bool => str_starts_with($name, 'NightWorksIO\\'))))
        ->toBe([]);
});
