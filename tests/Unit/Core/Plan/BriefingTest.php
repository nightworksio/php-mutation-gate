<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Plan\BriefingRecord;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\Multiple;
use NightWorksIO\MutationGate\Core\Test\SuiteName;

it('measures no peak and records first killers, by default', function (): void {
    expect(Briefing::standard()->peak())->toEqual(NotGiven::value())
        ->and(Briefing::standard()->matrix())->toBe(MatrixKind::FirstKiller);
});

it('takes each of what it tells on its own, leaving the other as it was', function (): void {
    $peak = MemoryCap::of(300, MemoryUnit::Megabytes);
    $weighed = Briefing::standard()->weighing($peak);
    $recording = Briefing::standard()->recording(MatrixKind::Full);

    expect($weighed->peak())->toBe($peak)
        ->and($weighed->matrix())->toBe(MatrixKind::FirstKiller)
        ->and($recording->matrix())->toBe(MatrixKind::Full)
        ->and($recording->peak())->toEqual(NotGiven::value())
        ->and($weighed->recording(MatrixKind::Full)->peak())->toBe($peak);
});

it('has every test judge the mutants by default, and one suite\'s tests alone where it is told', function (): void {
    $suite = Briefing::standard()->recording(MatrixKind::Full)->inSuite(SuiteName::of('unit'));

    expect(Briefing::standard()->suite())->toEqual(NotGiven::value())
        ->and($suite->suite())->toEqual(SuiteName::of('unit'))
        ->and($suite->matrix())->toBe(MatrixKind::Full)
        ->and($suite->isSecurityOnly())->toBeFalse();
});

it('tells every shard the multiple the plan learned, which its file holds, and none by default', function (): void {
    $learning = Briefing::standard()->learning(Multiple::of(4.5))->weighing(NotGiven::value())->onOwnScopeCoverage();

    expect(Briefing::standard()->multiple())->toBeInstanceOf(NotGiven::class)
        ->and($learning->multiple())->toEqual(Multiple::of(4.5))
        ->and(BriefingRecord::of($learning))->toMatchArray(['learnedMultiple' => 4.5])
        ->and(BriefingRecord::of(Briefing::standard()))->not->toHaveKey('learnedMultiple')
        ->and(BriefingRecord::read(Node::decode((string) json_encode(BriefingRecord::of($learning)))))
        ->toEqual($learning);
});
