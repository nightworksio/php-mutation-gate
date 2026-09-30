<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\ReportPath;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads the path an entry names, or takes the one it may leave out', function (): void {
    expect(ReportPath::from(Options::ofJson('{"path": "build/mutation.json"}'), '', 'needed'))->toEqual(ReportPath::at('build/mutation.json'))
        ->and(ReportPath::from(Options::none(), '.mutation-gate/publish', 'needed'))->toEqual(ReportPath::at('.mutation-gate/publish'));
});

it('refuses an entry with no path it needs, or one that is not text', function (string $options): void {
    expect(ReportPath::from(Options::ofJson($options), '', 'The report needs a path.'))
        ->toEqual(Invalid::because(Problem::at('path', 'The report needs a path.')));
})->with([
    'none' => ['{}'],
    'a number' => ['{"path": 3}'],
    'empty' => ['{"path": ""}'],
]);

it('writes a file, making the directories it needs, and reads it back', function (): void {
    $path = ReportPath::at(sprintf('%s/build/reports/mutation.json', Scratch::directory()));

    expect($path->write('{}'))->toEqual(Written::to($path->value()))
        ->and($path->read())->toBe('{}')
        ->and($path->value())->toEndWith('/build/reports/mutation.json');
});

it('names a file under a directory', function (): void {
    expect(ReportPath::at('build/html')->file('index.html')->value())->toBe('build/html/index.html');
});

it('reads nothing where there is no file, and says why it could not write one', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'taken/index.html/file', 'a directory in the way');
    $blocked = ReportPath::at(sprintf('%s/taken/index.html', $root));

    expect(ReportPath::at(sprintf('%s/none.json', $root))->read())->toBe('')
        ->and($blocked->write('<html>'))->toEqual(NotWritten::because(sprintf('%s/taken/index.html could not be written.', $root)));
});

it('asks an entry for the file a report is written to', function (): void {
    expect(ReportPath::ofFile(Options::ofJson('{"path": "build/matrix.csv"}'), 'The kill matrix'))->toEqual(ReportPath::at('build/matrix.csv'))
        ->and(ReportPath::ofFile(Options::none(), 'The kill matrix'))
        ->toEqual(Invalid::because(Problem::at('path', 'The kill matrix is written to a file, whose `path` the entry names.')));
});

it('streams a file piece by piece, and says why it could not', function (): void {
    $root = Scratch::directory();
    $path = ReportPath::at(sprintf('%s/build/matrix.csv', $root));
    Scratch::write($root, 'taken/matrix.csv/file', 'a directory in the way');

    expect($path->stream(['a,b', "\r\n"]))->toEqual(Written::to($path->value()))
        ->and($path->read())->toBe("a,b\r\n")
        ->and(ReportPath::at(sprintf('%s/taken/matrix.csv', $root))->stream(['x']))
        ->toEqual(NotWritten::because(sprintf('%s/taken/matrix.csv could not be written.', $root)));
});
