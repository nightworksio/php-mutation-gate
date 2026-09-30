<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Console\ProblemsReport;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Report\Problems;
use NightWorksIO\MutationGate\Core\Report\ProblemsShown;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;

afterEach(function (): void {
    Scratch::sweep();
});

it('frames the problems of a judgement for a background matcher, reading each mutated file it can', function (ProblemsShown $shown): void {
    $root = Scratch::directory();
    Scratch::write($root, 'src/Money.php', Verdicts::MONEY);
    $output = new BufferedOutput();
    $report = ProblemsReport::to($output, Root::of($root), $shown);

    $report->judging();

    expect($report->report(Verdicts::failing()))->toEqual(Written::to('the console'))
        ->and($output->fetch())->toBe(sprintf(
            "mutation-gate: judging\n%smutation-gate: judged\n",
            Problems::text(Verdicts::failing(), ['src/Money.php' => Contents::of(Verdicts::MONEY)], $shown),
        ));
})->with([ProblemsShown::All, ProblemsShown::Changed]);

it('prints what the project wrote as it is, reading no markup in it', function (): void {
    $output = new BufferedOutput();

    ProblemsReport::to($output, Root::of(Scratch::directory()), ProblemsShown::All)->report(Verdicts::failing());

    expect($output->fetch())->toContain('`$amount < $limit`');
});

it('shows every result unless asked for those on changed lines, and refuses anything else', function (): void {
    $console = static fn(ProblemsShown $shown): ProblemsReport => ProblemsReport::to(new ConsoleOutput(), Root::here(), $shown);

    expect(ProblemsReport::fromOptions(Options::none()))->toEqual($console(ProblemsShown::All))
        ->and(ProblemsReport::fromOptions(Options::ofJson('{"only": "all"}')))->toEqual($console(ProblemsShown::All))
        ->and(ProblemsReport::fromOptions(Options::ofJson('{"only": "changed"}')))->toEqual($console(ProblemsShown::Changed))
        ->and(ProblemsReport::fromOptions(Options::ofJson('{"only": "changed"}')))->not->toEqual($console(ProblemsShown::All))
        ->and(ProblemsReport::fromOptions(Options::ofJson('{"only": "survivors"}')))->toEqual(Invalid::because(Problem::at(
            'only',
            'The problems output shows every result, or only those on changed lines: "changed".',
        )))
        ->and(ProblemsReport::fromOptions(Options::ofJson('{"only": 3}')))->toBeInstanceOf(Invalid::class);
});
