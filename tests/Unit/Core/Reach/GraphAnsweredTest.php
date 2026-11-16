<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Reach\GraphAnswer;
use NightWorksIO\MutationGate\Core\Reach\GraphAnswered;

/** The answer the graph gave in this JSON, which holds its shape. */
function graphAnswered(string $json): GraphAnswer
{
    $read = GraphAnswered::of(Node::decode($json));

    return $read instanceof GraphAnswer ? $read : throw new LogicException($read->why());
}

it('reads what the graph answered of each file, none where it answered null or was not asked', function (): void {
    $answer = graphAnswered(<<<'JSON'
        {
            "reaching": {"src/Money.php": ["tests/MoneyTest.php"], "src/Clock.php": null},
            "joined": {"src/Money.php": ["src/Money.php", "src/Ledger.php"]},
            "named": {"src/Money.php": ["src/Rate.php"]},
            "wrong": ["tests/ClockTest.php"],
            "unplaced": {"src/Magic.php": "it calls a method by a name it builds"}
        }
        JSON);

    expect($answer->reaching(Path::of('src/Money.php')))->toEqual(Paths::of(Path::of('tests/MoneyTest.php')))
        ->and($answer->reaching(Path::of('src/Clock.php')))->toBeInstanceOf(NotGiven::class)
        ->and($answer->reaching(Path::of('src/Other.php')))->toBeInstanceOf(NotGiven::class)
        ->and($answer->joined(Path::of('src/Money.php')))->toEqual(Paths::of(Path::of('src/Money.php'), Path::of('src/Ledger.php')))
        ->and($answer->named(Path::of('src/Money.php')))->toEqual(Paths::of(Path::of('src/Rate.php')))
        ->and($answer->named(Path::of('src/Clock.php')))->toBeInstanceOf(NotGiven::class)
        ->and($answer->wrong())->toEqual(Paths::of(Path::of('tests/ClockTest.php')))
        ->and($answer->unplaced())->toBe(['src/Magic.php' => 'it calls a method by a name it builds']);
});

it('answers nothing where the graph answered in another shape, saying where', function (): void {
    $answer = GraphAnswered::of(Node::decode('{"reaching": {"src/Money.php": "tests/MoneyTest.php"}, "joined": {}, "named": {}, "wrong": [], "unplaced": {}}'));

    expect($answer)->toBeInstanceOf(NotWritten::class)
        ->and($answer instanceof NotWritten ? $answer->why() : '')->toStartWith('Outbreak\'s graph answered in another shape: ');
});
