<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Source;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// S3, over every method call each file under src and bin makes, read with the parser.

/** Each file that sends a signal by the raw send of the processes running, which SparedProcesses guards. */
const SIGNALS_RAW_SENDERS = [
    'src/Core/Runner/SparedProcesses.php',
];

it('sends another process a signal only through what the gate spares', function (): void {
    $scripts = array_values(array_filter(
        Tree::filesUnder('bin', ''),
        static fn(string $path): bool => str_starts_with((string) file_get_contents(Tree::at($path)), "#!/usr/bin/env php\n"),
    ));
    $senders = [];

    foreach ([...Source::under('src'), ...array_map(Source::at(...), $scripts)] as $source) {
        $senders = $source->callsMethod('signalledUnspared') ? [...$senders, $source->path] : $senders;
    }

    sort($senders);

    // S3
    expect($scripts)->not->toBe([])
        ->and($senders)->toBe(SIGNALS_RAW_SENDERS, 'Only SparedProcesses sends by RunningProcesses::signalledUnspared, so every signal to another process passes its refusals (S3, ADR-0310).');
});
