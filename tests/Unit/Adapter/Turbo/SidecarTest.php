<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Turbo\Sidecar;
use NightWorksIO\MutationGate\Core\Runner\Environment;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Turbo\Answer;
use NightWorksIO\MutationGate\Core\Turbo\NotAccelerated;
use NightWorksIO\MutationGate\Core\Turbo\Request;
use NightWorksIO\MutationGate\Tests\Fakes\ProcessesFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('hands the helper the request in a file under the workspace, answers what it printed, and removes the file', function (): void {
    $root = Scratch::directory();
    $request = '';
    $processes = new ProcessesFake(static function (ProcessCommand $command) use (&$request): Ran {
        $arguments = [...$command->arguments()];
        $request = (string) file_get_contents($arguments[3]);

        return Ran::exited(0, "{\"keys\":[]}\n", "{\"keys\":[]}\n");
    });

    $answer = Sidecar::of($processes, $root, ['/opt/helper', '--quiet'], Environment::telling('A', 'b'))
        ->answer(Request::ofText('{"protocol":1}'));
    $command = $processes->ran()[0];
    $arguments = [...$command->arguments()];
    $directory = $command->directory();

    expect($answer)->toEqual(Answer::ofText("{\"keys\":[]}\n"))
        ->and(array_slice($arguments, 0, 3))->toBe(['/opt/helper', '--quiet', 'answer'])
        ->and($arguments[3])->toStartWith(sprintf('%s/.mutation-gate/turbo/request-', $root))
        ->and($request)->toBe('{"protocol":1}')
        ->and($directory)->toBe($root)
        ->and(is_file($arguments[3]))->toBeFalse()
        ->and(iterator_to_array($command->environment(), preserve_keys: true))->toBe(['A' => 'b'])
        ->and($command->deadline())->toEqual(Seconds::of(120.0));
});

it('answers nothing where the helper fails, saying how', function (): void {
    $processes = new ProcessesFake(static fn(): Ran => Ran::exited(2, 'the request is not JSON', ''));

    expect(Sidecar::of($processes, Scratch::directory(), ['/opt/helper'], Environment::none())->answer(Request::ofText('{')))
        ->toEqual(NotAccelerated::because('The helper answered nothing: exit 2: the request is not JSON'));
});

it('answers nothing where the request cannot be written', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, '.mutation-gate/turbo', 'a file where the directory would be');
    $processes = new ProcessesFake(static fn(): Ran => Ran::exited(0, '{}'));

    $answer = Sidecar::of($processes, $root, ['/opt/helper'], Environment::none())->answer(Request::ofText('{}'));

    expect($answer)->toBeInstanceOf(NotAccelerated::class)
        ->and($answer instanceof NotAccelerated ? $answer->why() : '')->toStartWith('The request could not be written to ')
        ->and($processes->ran())->toBe([]);
});
