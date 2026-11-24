<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\RoundEvent;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;

it('says why a round judged nothing where it could not', function (): void {
    expect(RoundEvent::line(stopped: false, ended: CannotJudge::because('no coverage driver'), mutants: 0))
        ->toBe('{"round":"unjudged","mutants":0,"why":"no coverage driver"}');
});

it('says a round ended on a configuration that is not valid', function (): void {
    expect(RoundEvent::line(stopped: false, ended: Invalid::because(Problem::at('runner', 'expected a name')), mutants: 0))
        ->toBe('{"round":"invalid","mutants":0,"why":null}');
});

it('says a change stopped a round, with the mutants it had said, and no reason of what it left', function (): void {
    expect(RoundEvent::line(stopped: true, ended: CannotJudge::because('stopped'), mutants: 3))
        ->toBe('{"round":"changed","mutants":3,"why":null}');
});
