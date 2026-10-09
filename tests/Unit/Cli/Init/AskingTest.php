<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Init\Asking;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Asking at a console that holds these answers, taking answers or not, in this environment.
 *
 * @param list<string> $answers
 */
function askingWith(array $answers, bool $interactive, Variables $environment): Asking
{
    $input = new ArrayInput([]);
    $stream = fopen('php://memory', 'r+');

    if (is_resource($stream)) {
        fwrite($stream, implode(PHP_EOL, $answers));
        rewind($stream);
        $input->setStream($stream);
    }

    $input->setInteractive($interactive);

    return Asking::at($input, new BufferedOutput(), $environment);
}

it('takes the answers a person gives', function (): void {
    $asking = askingWith(['b', 'y'], interactive: true, environment: Variables::of([]));

    expect($asking->isInteractive())->toBeTrue()
        ->and($asking->choice('Which?', ['a', 'b'], 'a'))->toBe('b')
        ->and($asking->confirms('Sure?', default: false))->toBeTrue();
});

it('takes each default where a person presses Enter', function (): void {
    $asking = askingWith(['', ''], interactive: true, environment: Variables::of([]));

    expect($asking->choice('Which?', ['a', 'b'], 'b'))->toBe('b')
        ->and($asking->confirms('Sure?', default: true))->toBeTrue();
});

it('asks nobody, taking each default, without a terminal or in CI', function (bool $interactive, Variables $environment): void {
    $asking = askingWith(['b', 'n'], $interactive, $environment);

    expect($asking->isInteractive())->toBeFalse()
        ->and($asking->choice('Which?', ['a', 'b'], 'a'))->toBe('a')
        ->and($asking->confirms('Sure?', default: true))->toBeTrue();
})->with([
    'no terminal' => [false, Variables::of([])],
    'CI' => [true, Variables::of(['CI' => 'true'])],
]);
