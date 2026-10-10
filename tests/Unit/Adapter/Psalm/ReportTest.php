<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Psalm\PsalmXml;
use NightWorksIO\MutationGate\Adapter\Psalm\Report;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\FindingFiles;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Runner\ChildProcess;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

/** The files Psalm's findings sit in, under the project it is told of. */
function reportFiles(): FindingFiles
{
    return FindingFiles::under(Root::of('/work'));
}

/** The config Psalm is told it runs with. */
function reportXml(): PsalmXml
{
    $xml = PsalmXml::read(Contents::of('<psalm><projectFiles><directory name="src"/></projectFiles></psalm>'), Path::of('psalm.xml'), Root::of('/work'));

    return $xml instanceof PsalmXml ? $xml : throw new LogicException('The config is unread.');
}

afterEach(function (): void {
    Scratch::sweep();
});


it('reads each issue as a finding in its file, by its type and message, an error by its severity', function (int $exit): void {
    $files = reportFiles();
    $xml = reportXml();

    $report = '[{"severity": "error", "type": "InvalidReturnType", "message": "Wrong.", "file_path": "/work/src/Money.php"},'
        . ' {"severity": "info", "type": "MissingParamType", "message": "Untyped.", "file_path": "src/Wallet.php"}]';

    expect(Report::of(ChildProcess::exited($exit, $report, ''), $files, $xml))->toEqual(Findings::of(
        Finding::error(Path::of('src/Money.php'), 'InvalidReturnType', 'Wrong.'),
        Finding::lesser(Path::of('src/Wallet.php'), 'MissingParamType', 'Untyped.'),
    ));
})->with(['nothing found' => [0], 'errors found' => [2]]);

it('reads an empty report as no findings', function (): void {
    $files = reportFiles();
    $xml = reportXml();

    expect(Report::of(ChildProcess::exited(0, '[]', ''), $files, $xml))->toEqual(Findings::none());
});

it('cannot judge where Psalm did not finish, or wrote no list', function (ChildProcess $run, string $why): void {
    $files = reportFiles();
    $xml = reportXml();

    expect(Report::of($run, $files, $xml))->toEqual(CannotJudge::because($why));
})->with([
    'a crash' => [fn(): ChildProcess => ChildProcess::exited(1, '[]', 'Fatal.'), 'Psalm wrote no report (exit 1: Fatal.).'],
    'no JSON' => [fn(): ChildProcess => ChildProcess::exited(0, 'Deprecated: x', ''), 'Psalm wrote no report (exit 0: ).'],
    'an object' => [fn(): ChildProcess => ChildProcess::exited(2, '{"issues": []}', ''), 'Psalm wrote no report (exit 2: ).'],
]);

it('names a file in a directory the config names through a link as the config names it', function (): void {
    $project = Scratch::directory();
    $real = Scratch::directory();
    symlink($real, sprintf('%s/src', $project));
    $xml = PsalmXml::read(Contents::of('<psalm><projectFiles><directory name="src"/></projectFiles></psalm>'), Path::of('psalm.xml'), Root::of($project));
    $report = sprintf('[{"severity": "error", "type": "A", "message": "b", "file_path": "%s/Money.php"}]', realpath($real));

    expect($xml instanceof PsalmXml ? Report::of(ChildProcess::exited(2, $report, ''), FindingFiles::under(Root::of($project)), $xml) : $xml)
        ->toEqual(Findings::of(Finding::error(Path::of('src/Money.php'), 'A', 'b')));
});
