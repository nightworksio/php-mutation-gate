<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\End;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Refusal;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Worker;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Workplace;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use NightWorksIO\MutationGate\Tests\Support\WarmWorkers;

afterEach(function (): void {
    Scratch::sweep();
});

it('starts no run once the job\'s end has come', function (): void {
    $workplace = WarmWorkers::place([WarmWorkers::run(['SAID' => 'late', 'CODE' => '0'])], end: microtime(as_float: true) - 1.0);

    Worker::at($workplace->directory(), 0)->run(WarmWorkers::forking('exit 0', $workplace->out(0)));

    expect(End::ranIn($workplace, 0))->toEqual(NotGiven::value());
});

it('forks nothing, and says why, where its job cannot be read', function (): void {
    $missing = Workplace::at(Scratch::directory());
    $garbled = WarmWorkers::place([]);
    file_put_contents($garbled->job(), '{"autoloader": 3}');

    $code = Worker::at($missing->directory(), 2)->run(WarmWorkers::forking('exit 0', $missing->out(2)));
    Worker::at($garbled->directory(), 0)->run(WarmWorkers::forking('exit 0', $garbled->out(0)));
    $read = Refusal::readFrom($garbled->refused(0));

    expect($code)->toBe(0)
        ->and(Refusal::readFrom($missing->refused(2)))->toEqual(Refusal::guarded(sprintf('The worker could not read its job: %s', $missing->job())))
        ->and($read instanceof Refusal ? $read->reason() : '')->toStartWith('The worker could not read its job: ')
        ->and(Worker::at($missing->directory(), 2)->refused(Refusal::unforkable('no pcntl')))->toBe(0);
});

it('runs the worker script in this package\'s own directory', function (): void {
    expect(Worker::script())->toBe(Tree::at('bin/mutation-gate-worker'))
        ->and(is_file(Worker::script()))->toBeTrue();
});

it('forks nothing, and says why, from a boot the guard refuses', function (): void {
    $workplace = WarmWorkers::place([WarmWorkers::run(['CODE' => '0'])], new NotGiven(), new NotGiven(), Tree::at('src/Core/NotGiven.php'));

    $code = Worker::at($workplace->directory(), 0)->run(WarmWorkers::forking('exit "$CODE"', $workplace->out(0)));

    expect($code)->toBe(0)
        ->and(End::ranIn($workplace, 0))->toEqual(NotGiven::value())
        ->and(Refusal::readFrom($workplace->refused(0)))->toBeInstanceOf(Refusal::class);
});
