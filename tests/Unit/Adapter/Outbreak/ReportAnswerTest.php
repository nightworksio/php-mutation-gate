<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Outbreak\ReportAnswer;
use NightWorksIO\MutationGate\Core\Coverage\MapOutline;
use NightWorksIO\MutationGate\Core\Coverage\RecordedBeside;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Runner\RanReport;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Written;

$report = static fn(): RanReport => RanReport::at(
    '/p/coverage.php',
    4,
    RecordedBeside::nothing(),
    static fn(string $file): Path => Path::of(substr($file, strlen('/p/'))),
);

it('reads an outline, each file at its path in the project and each test with the files at its places', function () use ($report): void {
    $said = Node::decode('{"outbreak":1,"outline":{"files":["/p/a.php","/p/b.php"],"tests":[["A::a",[1,0,1]],["B::b",[]]]}}');
    $a = Path::of('a.php');
    $b = Path::of('b.php');

    expect(ReportAnswer::outline($said, $report()))->toEqual(MapOutline::listed(
        TestIds::of(TestId::of('A::a'), TestId::of('B::b')),
        ['A::a' => ['b.php' => $b, 'a.php' => $a], 'B::b' => []],
        ['/p/a.php' => $a, '/p/b.php' => $b],
    ));
});

it('says why an answer holds no outline: it declined, or it is not one', function (string $answer, string $why) use ($report): void {
    expect(ReportAnswer::outline(Node::decode($answer), $report()))->toEqual(NotWritten::because($why));
})->with([
    'declined' => ['{"declined":"expected UTF-8 at byte 3"}', 'Outbreak declined to read the report: expected UTF-8 at byte 3'],
    'no outline' => ['{"outbreak":1}', 'Outbreak did not read the report: it answered {"outbreak":1}'],
    'a place past the files' => [
        '{"outline":{"files":[],"tests":[["A::a",[0]]]}}',
        'Outbreak did not read the report: it answered {"outline":{"files":[],"tests":[["A::a",[0]]]}}',
    ],
    'a test without its places' => [
        '{"outline":{"files":[],"tests":[["A::a"]]}}',
        'Outbreak did not read the report: it answered {"outline":{"files":[],"tests":[["A::a"]]}}',
    ],
]);

it('reads that a map was written where it was asked, and why not otherwise', function (): void {
    expect(ReportAnswer::written(Node::decode('{"written":1}'), '/p/map.json.gz'))->toEqual(Written::to('/p/map.json.gz'))
        ->and(ReportAnswer::written(Node::decode('{"declined":"no"}'), '/p/map.json.gz'))
        ->toEqual(NotWritten::because('Outbreak declined to read the report: no'))
        ->and(ReportAnswer::written(Node::decode('{"written":0}'), '/p/map.json.gz'))
        ->toEqual(NotWritten::because('Outbreak did not read the report: it answered {"written":0}'))
        ->and(ReportAnswer::written(Node::decode('{"declined":1}'), '/p/map.json.gz'))
        ->toEqual(NotWritten::because('Outbreak did not read the report: it answered {"declined":1}'));
});
