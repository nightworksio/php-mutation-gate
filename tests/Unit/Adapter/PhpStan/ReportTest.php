<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpStan\Report;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\FindingFiles;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Runner\ChildProcess;

it('cannot judge an analysis PHPStan did not finish, by an error of no file', function (): void {
    expect(Report::of(
        ChildProcess::exited(1, '{"totals": {}, "files": {}, "errors": ["Internal error: out of memory."]}', ''),
        FindingFiles::under(Root::of('/p')),
    ))->toEqual(CannotJudge::because('PHPStan did not finish its analysis: Internal error: out of memory.'));
});

it('cannot judge from output that is no report, an exit that is no finished analysis, or a PHPStan that never ran', function (ChildProcess $phpstan): void {
    expect(Report::of($phpstan, FindingFiles::under(Root::of('/p'))))
        ->toEqual(CannotJudge::because(sprintf('PHPStan wrote no report (%s).', $phpstan->said())));
})->with([
    'no report' => [ChildProcess::exited(1, 'File passed to --tmp-file option does not exist', '')],
    'a crash' => [ChildProcess::exited(255, '{"totals": {}, "files": {}, "errors": []}', 'Fatal error')],
    'never ran' => [ChildProcess::neverStarted('No such directory.')],
]);

it('places an error in a trait in the trait\'s file, not the class it was analysed in the context of', function (): void {
    $report = '{"totals": {}, "files": {"/p/src/Counts.php (in context of class Tally)": {"messages": ['
        . '{"message": "Method Tally::twice() should return int but returns string.", "identifier": "return.type"}]}}, "errors": []}';

    expect(Report::of(ChildProcess::exited(1, $report, ''), FindingFiles::under(Root::of('/p'))))
        ->toEqual(Findings::of(Finding::error(Path::of('src/Counts.php'), 'return.type', 'Method Tally::twice() should return int but returns string.')));
});
