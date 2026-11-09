<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Console\ConsoleReport;
use NightWorksIO\MutationGate\Core\Config\CommentShows;
use NightWorksIO\MutationGate\Core\Cost\SavingsWindow;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\Report\Digest\Digest;
use NightWorksIO\MutationGate\Core\Report\Digest\SurvivorsShown;
use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Report\Markdown;
use NightWorksIO\MutationGate\Core\Report\Page\Details;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;
use Symfony\Component\Console\Output\BufferedOutput;

$said = static fn(Warning ...$warnings): Verdict => Verdicts::passing()->withWarnings(Warnings::of(...$warnings));

$reports = static fn(Verdict $verdict): array => [
    'json' => JsonReport::encode($verdict),
    'comment' => Markdown::comment($verdict, CommentShows::of()),
    'summary' => Markdown::summary($verdict, '', SavingsWindow::standard(), new DateTimeImmutable('2026-11-07')),
    'html' => Details::html(JsonReport::of($verdict)),
    'digest' => Digest::overview(Node::decode(JsonReport::encode($verdict)), SurvivorsShown::first(), GateCommand::installed()),
    'console' => (static function () use ($verdict): string {
        $output = new BufferedOutput();
        ConsoleReport::to($output)->report($verdict);

        return $output->fetch();
    })(),
];

it('splits what asks for action from what asks for nothing, in the order each was raised', function (): void {
    $warnings = Warnings::of(Warning::that('one'), Warning::noting('two'), Warning::that('three'), Warning::noting('four'));

    expect(array_map(static fn(Warning $warning): string => $warning->text(), [...$warnings->actionable()]))->toBe(['one', 'three'])
        ->and(array_map(static fn(Warning $warning): string => $warning->text(), [...$warnings->notes()]))->toBe(['two', 'four'])
        ->and(count($warnings))->toBe(4);
});

it('prints no Warnings section where a run only notes, and says the note in every report', function (string $report) use ($said, $reports): void {
    $printed = $reports($said(Warning::noting('worth knowing')))[$report];

    expect($printed)->toContain('worth knowing')
        ->and($printed)->not->toContain('Warnings');
})->with(['comment', 'summary', 'html', 'console']);

it('prints a Notes section only where a run notes something, apart from its warnings', function (string $report) use ($said, $reports): void {
    $both = $reports($said(Warning::that('act on it'), Warning::noting('worth knowing')))[$report];
    $warned = $reports($said(Warning::that('act on it')))[$report];

    expect($both)->toContain('Warnings')
        ->and($both)->toContain('Notes')
        ->and($both)->toContain('act on it')
        ->and($both)->toContain('worth knowing')
        ->and((int) strpos($both, 'act on it'))->toBeLessThan((int) strpos($both, 'worth knowing'))
        ->and($warned)->not->toContain('Notes');
})->with(['comment', 'summary', 'html', 'console']);

it('keeps them apart in the JSON report and the digest', function () use ($said, $reports): void {
    $verdict = $said(Warning::that('act on it'), Warning::noting('worth knowing'));
    $report = JsonReport::of($verdict);

    expect($report['warnings'])->toBe(['act on it'])
        ->and($report['notes'])->toBe(['worth knowing'])
        ->and($reports($verdict)['digest'])->toContain("warn act on it\nnote worth knowing\n");
});
