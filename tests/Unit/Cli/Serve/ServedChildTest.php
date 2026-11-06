<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Serve\ServedChild;
use NightWorksIO\MutationGate\Core\Serve\ServedVariables;
use NightWorksIO\MutationGate\Core\Serve\ServeIdentity;
use NightWorksIO\MutationGate\Core\Serve\ServeRequest;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    putenv('COLUMNS');
    putenv(ServedVariables::SERVED);
    Scratch::sweep();
});

it('makes the child ready to run a request as its client would have: its variables, the project\'s root and what it read', function (): void {
    $root = (string) realpath(Scratch::directory());
    $was = (string) getcwd();
    $request = ServeRequest::running(ServeIdentity::of($root, '1.0.0', 'lock', 'config'), ['gate', 'pre-push', 'origin'], ['COLUMNS' => '77'], "refs\n", decorated: false);

    try {
        $input = ServedChild::prepared($request, $root);
        $in = (string) getcwd();
    } finally {
        chdir($was);
    }

    $stream = $input->getStream();

    expect([getenv('COLUMNS'), getenv(ServedVariables::SERVED)])->toBe(['77', '1'])
        ->and($in)->toBe($root)
        ->and($input->getFirstArgument())->toBe('pre-push')
        ->and(is_resource($stream) ? stream_get_contents($stream) : '')->toBe("refs\n");
});
