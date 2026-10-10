<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Mago\Report;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\FindingFiles;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Runner\ChildProcess;

it('cannot judge from an exit that is no finished analysis, output that is no report, or a Mago that never ran', function (ChildProcess $mago): void {
    expect(Report::of($mago, FindingFiles::under(Root::of('/p'))))
        ->toEqual(CannotJudge::because(sprintf('Mago wrote no report (%s).', $mago->said())));
})->with([
    'a usage error' => [fn(): ChildProcess => ChildProcess::exited(2, '{"issues": []}', 'ERROR --substitute: no such file')],
    'no report' => [fn(): ChildProcess => ChildProcess::exited(1, 'Mago panicked', '')],
    'never ran' => [fn(): ChildProcess => ChildProcess::neverStarted('No such directory.')],
]);

it('places an issue in the file of its primary annotation, whatever annotation comes first', function (): void {
    $issue = '{"level": "Error", "code": "duplicate-definition", "message": "Class Money is defined twice.", "annotations": ['
        . '{"kind": "Secondary", "span": {"file_id": {"path": "/p/src/Dup.php"}}}, '
        . '{"kind": "Primary", "span": {"file_id": {"path": "/p/src/Money.php"}}}]}';

    expect(Report::of(ChildProcess::exited(1, sprintf('{"issues": [%s]}', $issue), ''), FindingFiles::under(Root::of('/p'))))
        ->toEqual(Findings::of(Finding::error(Path::of('src/Money.php'), 'duplicate-definition', 'Class Money is defined twice.')));
});

it('cannot judge an issue that is in no file, as an analysis that did not finish', function (string $annotations): void {
    $issue = sprintf('{"level": "Note", "code": "unplaced", "message": "Somewhere.", "annotations": %s}', $annotations);

    expect(Report::of(ChildProcess::exited(0, sprintf('{"issues": [%s]}', $issue), ''), FindingFiles::under(Root::of('/p'))))
        ->toEqual(CannotJudge::because('Mago reported unplaced in no file.'));
})->with([
    'no annotation' => ['[]'],
    'only a secondary one' => ['[{"kind": "Secondary", "span": {"file_id": {"path": "/p/src/Money.php"}}}]'],
]);
