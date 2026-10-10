<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Turbo\Answer;
use NightWorksIO\MutationGate\Core\Turbo\AnsweredKeys;
use NightWorksIO\MutationGate\Core\Turbo\NotAccelerated;

$asked = [Path::of('tests/MoneyTest.php'), Path::of('tests/OtherTest.php')];
$key = static fn(string $seed): string => hash('sha256', $seed);
$read = static fn(mixed $answer): array|NotAccelerated => AnsweredKeys::read(Answer::ofText((string) json_encode($answer)), $asked);

it('reads each key by the place of the path it answers, past a trailing newline', function () use ($asked, $key): void {
    $answer = Answer::ofText(sprintf("%s\n", json_encode(['keys' => [$key('a'), $key('b')], 'protocol' => 1])));

    expect(AnsweredKeys::read($answer, $asked))->toEqual([Digest::of($key('a')), Digest::of($key('b'))]);
});

it('passes on why there is no answer', function () use ($asked): void {
    expect(AnsweredKeys::read(NotAccelerated::because('turned off'), $asked))->toEqual(NotAccelerated::because('turned off'));
});

it('refuses an answer it cannot read, in another protocol, of another count or with a key that is no SHA-256', function () use (
    $asked,
    $key,
    $read,
): void {
    expect(AnsweredKeys::read(Answer::ofText('not json'), $asked))->toEqual(NotAccelerated::because(
        'The helper\'s answer is not what this gate reads: the answer.protocol is missing.',
    ))
        ->and($read(['protocol' => 2, 'keys' => [$key('a'), $key('b')]]))
        ->toEqual(NotAccelerated::because('The helper answered in protocol 2, and this gate reads 1.'))
        ->and($read(['protocol' => 1, 'keys' => [$key('a')]]))
        ->toEqual(NotAccelerated::because('The helper answered 1 keys for 2 paths.'))
        ->and($read(['protocol' => 1, 'keys' => [$key('a'), 'ABC']]))
        ->toEqual(NotAccelerated::because('The helper answered ABC for tests/OtherTest.php, which is no SHA-256.'))
        ->and($read(['protocol' => 1, 'keys' => [$key('a'), 7]]))->toBeInstanceOf(NotAccelerated::class)
        ->and($read(['protocol' => 1]))->toBeInstanceOf(NotAccelerated::class);
});
