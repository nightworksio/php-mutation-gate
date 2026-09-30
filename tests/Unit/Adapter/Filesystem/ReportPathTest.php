<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\ReportPath;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads the path an entry names, or takes the one it may leave out', function (): void {
    expect(ReportPath::named(Configs::options('{"path": "build/mutation.json"}'), 'needed'))->toEqual(ReportPath::at('build/mutation.json'))
        ->and(ReportPath::namedOr(Configs::options('{"path": "build/badge"}'), '.mutation-gate/publish'))->toEqual(ReportPath::at('build/badge'))
        ->and(ReportPath::namedOr(Options::none(), '.mutation-gate/publish'))->toEqual(ReportPath::at('.mutation-gate/publish'))
        ->and(ReportPath::namedOr(Configs::options('{"path": "/tmp/x"}'), '.mutation-gate/publish'))
        ->toEqual(Invalid::because(Problem::at('path', 'expected a path inside the project, got "/tmp/x"')));
});

it('refuses an entry with no path it needs, or one that is not a path inside the project', function (
    string $options,
    string $problem,
): void {
    expect(ReportPath::named(Configs::options($options), 'The report needs a path.'))
        ->toEqual(Invalid::because(Problem::at('path', $problem)));
})->with([
    'none' => ['{}', 'The report needs a path.'],
    'a number' => ['{"path": 3}', 'expected a path, got 3'],
    'empty' => ['{"path": ""}', 'expected a path, got ""'],
    'absolute' => ['{"path": "/tmp/x"}', 'expected a path inside the project, got "/tmp/x"'],
    'up out of the project' => ['{"path": "../x"}', 'expected a path inside the project, got "../x"'],
]);

it('takes an absolute path the command line names', function (): void {
    $named = Options::at(Configs::options('{"path": "/tmp/x"}')->written(), ProjectRoot::commandLine());

    expect(ReportPath::named($named, 'needed'))->toEqual(ReportPath::at('/tmp/x'));
});

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
    expect(ReportPath::ofFile(Configs::options('{"path": "build/matrix.csv"}'), 'The kill matrix'))->toEqual(ReportPath::at('build/matrix.csv'))
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

it('writes a path from the project inside it, and nothing that leads out of it, up or through a link', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'project/composer.json', '{}');
    Scratch::write($root, 'outside/kept', '');
    symlink(sprintf('%s/outside', $root), sprintf('%s/project/linked', $root));
    $directory = (string) getcwd();
    chdir(sprintf('%s/project', $root));
    $inside = ReportPath::at('build/mutation.json')->write('{}');
    $up = ReportPath::at('../escaped.json')->write('{}');
    $linked = ReportPath::at('linked/escaped.json')->stream(['{}']);
    chdir($directory);

    expect($inside)->toEqual(Written::to('build/mutation.json'))
        ->and(file_get_contents(sprintf('%s/project/build/mutation.json', $root)))->toBe('{}')
        ->and($up)->toEqual(NotWritten::because('../escaped.json leads out of ., so the gate does not read or write it.'))
        ->and($linked)->toEqual(NotWritten::because('linked/escaped.json leads out of ., so the gate does not read or write it.'))
        ->and(glob(sprintf('%s/*.json', $root)))->toBe([])
        ->and(glob(sprintf('%s/outside/*.json', $root)))->toBe([]);
});
