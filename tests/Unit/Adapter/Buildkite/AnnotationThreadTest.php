<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Buildkite\AnnotationThread;
use NightWorksIO\MutationGate\Core\Delivery\CommentWrite;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Tests\Fakes\ProcessesFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

it('runs no agent, and says so, where the body cannot be written to its scratch directory', function (bool $aFile): void {
    $agent = new ProcessesFake(static fn(): Ran => Ran::finished(succeeded: true, output: ''));
    $root = Scratch::directory();
    Scratch::write($root, 'taken', '');
    $scratch = $aFile ? sprintf('%s/taken', $root) : $root;
    chmod($root, 0o500);

    try {
        $written = AnnotationThread::of($agent, '/project', 'https://buildkite.com/acme/shop/builds/9', inBuild: true, scratch: $scratch)
            ->written('body', CommentWrite::sticky());
    } finally {
        chmod($root, 0o700);
    }

    expect($written)->toEqual(NotWritten::because('The build annotation could not be written (its body could not be written to a file).'))
        ->and($agent->ran())->toBe([]);
})->with([
    'a file, not a directory' => [true],
    'a directory it cannot write' => [false],
]);

it('holds the body in its scratch directory for the agent, and leaves nothing there once annotated', function (): void {
    $scratch = Scratch::directory();
    $held = [];
    $agent = new ProcessesFake(static function (ProcessCommand $command) use (&$held): Ran {
        $arguments = [...$command->arguments()];
        $held[] = [dirname($arguments[5]), (string) file_get_contents($arguments[5])];

        return Ran::finished(succeeded: true, output: '');
    });
    AnnotationThread::of($agent, '/project', 'https://buildkite.com/acme/shop/builds/9', inBuild: true, scratch: $scratch)
        ->written('body', CommentWrite::sticky());

    expect($held)->toBe([[realpath($scratch), 'body']])
        ->and(scandir($scratch))->toBe(['.', '..']);
});
