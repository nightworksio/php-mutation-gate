<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\SampleRecord;
use NightWorksIO\MutationGate\Core\Sample\Candidate;
use NightWorksIO\MutationGate\Core\Sample\Confidence;
use NightWorksIO\MutationGate\Core\Sample\Sample;
use NightWorksIO\MutationGate\Core\Sample\Unsampled;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

$sample = static fn(): Sample => Sample::drawn('a1b2c3', Confidence::NinetyNine, 1.5)->holding(
    Units::of(Unit::file(Path::of('src/A.php')), Unit::held(Path::of('src/Kernel'), Group::named('holds:src/Kernel'))),
    Unsampled::none()->with(Candidate::of(Unit::file(Path::of('src/B.php')), 7, NotGiven::value())),
);

it('writes the seed, confidence, margin and the units a sample runs and leaves out, and reads them back', function () use ($sample): void {
    $field = SampleRecord::field($sample());

    expect($field)->toBe(['sample' => [
        'seed' => 'a1b2c3',
        'confidence' => 99,
        'margin' => 1.5,
        'sampled' => [['path' => 'src/A.php'], ['path' => 'src/Kernel', 'group' => 'holds:src/Kernel']],
        'unsampled' => [['path' => 'src/B.php', 'mutants' => 7]],
    ]])
        ->and(SampleRecord::read(Node::decode((string) json_encode($field))->field('sample')))->toEqual($sample());
});

it('writes nothing for a run that is not sampled, and reads no field as no sample', function (): void {
    expect(SampleRecord::field(Sample::none()))->toBe([])
        ->and(SampleRecord::read(Node::decode('{}')->field('sample')))->toEqual(Sample::none());
});

it('reads a margin written as a whole number', function (): void {
    $read = SampleRecord::read(Node::decode('{"seed": "s", "confidence": 95, "margin": 2, "sampled": [], "unsampled": []}'));

    expect($read->margin())->toBe(2.0)
        ->and($read->confidence())->toBe(Confidence::NinetyFive);
});

it('refuses a sample field not so shaped', function (string $field): void {
    expect(static fn(): Sample => SampleRecord::read(Node::decode($field)))->toThrow(NotInShape::class);
})->with([
    'a confidence it does not offer' => ['{"seed": "s", "confidence": 80, "margin": 2, "sampled": [], "unsampled": []}'],
    'no seed' => ['{"confidence": 95, "margin": 2, "sampled": [], "unsampled": []}'],
    'an unsampled unit with no mutants' => ['{"seed": "s", "confidence": 95, "margin": 2, "sampled": [], "unsampled": [{"path": "src/B.php"}]}'],
]);
