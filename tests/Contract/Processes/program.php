<?php

declare(strict_types=1);

// The program the Processes contract cases run: `say <text> <exit code>`
// prints the text and exits so, `tell <variable>` prints what the process was
// told, and anything else prints the directory it runs in.

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

echo (string) getcwd();
