<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\CheckBatch;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\Ran;

it('runs the checks that need a command, answers each by its place among the checks, and keeps those answered already', function (): void {
    $known = Findings::of(Finding::error(Path::of('src/Money.php'), 'known', 'Answered before any run.'));
    $batch = CheckBatch::of(ProcessCommand::of('/project', 'first'), $known, ProcessCommand::of('/project', 'second'));
    $answer = static fn(Ran $ran, int $at): Findings => Findings::of(Finding::error(Path::of('src/Money.php'), (string) $at, $ran->output()));

    $answers = $batch->answered(
        ProcessEnds::of(Ran::finished(succeeded: true, output: 'one'), Ran::finished(succeeded: true, output: 'two')),
        $answer,
        static fn(): Ran => Ran::finished(succeeded: false, output: 'never run again'),
    );

    expect(array_map(static fn(ProcessCommand $command): array => [...$command->arguments()], $batch->commands()))
        ->toBe([['first'], ['second']])
        ->and([...$answers])->toEqual([
            Findings::of(Finding::error(Path::of('src/Money.php'), '0', 'one')),
            $known,
            Findings::of(Finding::error(Path::of('src/Money.php'), '2', 'two')),
        ]);
});

it('cannot judge a check whose command never started', function (): void {
    $batch = CheckBatch::of(ProcessCommand::of('/project', 'first'), ProcessCommand::of('/project', 'second'));

    $answers = $batch->answered(
        ProcessEnds::of(Ran::finished(succeeded: true, output: 'one')),
        static fn(): Findings => Findings::none(),
        static fn(): Ran => Ran::finished(succeeded: false, output: 'never run again'),
    );

    expect([...$answers])->toEqual([Findings::none(), CannotJudge::because('The analyser did not start the check.')]);
});

it('runs a check that ended on its own with no answer once more, alone, and keeps one stopped at its limit as it is', function (): void {
    $batch = CheckBatch::of(
        ProcessCommand::of('/project', 'crashed'),
        ProcessCommand::of('/project', 'stopped'),
        ProcessCommand::of('/project', 'answered'),
    );
    $again = [];
    $answer = static fn(Ran $ran): Findings|CannotJudge => $ran->output() === 'report'
        ? Findings::none()
        : CannotJudge::because(sprintf('No report: %s.', $ran->output()));

    $answers = $batch->answered(
        ProcessEnds::of(Ran::finished(succeeded: false, output: 'crash'), Ran::stopped('slow'), Ran::finished(succeeded: true, output: 'report')),
        $answer,
        static function (ProcessCommand $command) use (&$again): Ran {
            $again[] = [...$command->arguments()];

            return Ran::finished(succeeded: true, output: 'report');
        },
    );

    expect([...$answers])->toEqual([Findings::none(), CannotJudge::because('No report: slow.'), Findings::none()])
        ->and($again)->toBe([['crashed']]);
});
