<?php

declare(strict_types=1);

// The program the Processes contract cases run: `say <text> <exit code>`
// prints the text and exits so, `tell <variable>` prints what the process was
// told, `stall <file>` adds a line to the file and then loops while the file is there,
// `both <out> <err>` prints one text on each stream,
// `await <file>` waits for the file, up to ten seconds, and prints it, and
// anything else prints the directory it runs in.

$verb = $argv[1] ?? '';
$first = $argv[2] ?? '';

if ($verb === 'say') {
    echo $first;

    exit((int) ($argv[3] ?? '0'));
}

if ($verb === 'tell') {
    echo (string) getenv($first);

    exit(0);
}

if ($verb === 'both') {
    fwrite(STDOUT, $first);
    fwrite(STDERR, $argv[3] ?? '');

    exit(0);
}

if ($verb === 'await') {
    $until = microtime(as_float: true) + 10.0;

    while (! is_file($first) && microtime(as_float: true) < $until) {
        clearstatcache();
    }

    echo is_file($first) ? (string) file_get_contents($first) : '';

    exit(0);
}

if ($verb === 'stall') {
    file_put_contents($first, "started\n", FILE_APPEND);

    while (is_file($first)) {
        clearstatcache();
    }
}

echo (string) getcwd();
