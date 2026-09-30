<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\KillMatrixFile;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Report\KillMatrixCsv;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

it('streams the kill matrix to the path its entry names', function (): void {
    $file = sprintf('%s/build/kill-matrix.csv', Scratch::directory());
    $verdict = Verdicts::named('with a matrix');

    expect(KillMatrixFile::fromOptions(Options::ofJson((string) json_encode(['path' => $file]))))->toEqual(KillMatrixFile::at($file))
        ->and(KillMatrixFile::at($file)->report($verdict))->toEqual(Written::to($file))
        ->and(file_get_contents($file))->toBe(implode('', iterator_to_array(KillMatrixCsv::records($verdict), preserve_keys: false)));
});

it('needs a path', function (): void {
    expect(KillMatrixFile::fromOptions(Options::none()))->toEqual(Invalid::because(Problem::at(
        'path',
        'The kill matrix is written to a file, whose `path` the entry names.',
    )));
});
