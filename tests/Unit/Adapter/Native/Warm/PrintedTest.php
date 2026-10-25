<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Native\Warm\Job;
use NightWorksIO\MutationGate\Adapter\Native\Warm\Printed;
use NightWorksIO\MutationGate\Adapter\Native\Warm\Workplace;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('holds the bytes printed from where the worker\'s files stood to where they stand now, and reads back as it wrote', function (): void {
    $workplace = Workplace::at(sprintf('%s/warm', Scratch::directory()));
    $workplace->opened(Job::of('', NotGiven::value(), [], NotGiven::value(), []));
    $from = Printed::from($workplace, 0);
    file_put_contents($workplace->out(0), 'first');
    $first = $from->untilNow($workplace);
    $then = Printed::from($workplace, 0);
    file_put_contents($workplace->out(0), ' second', FILE_APPEND);
    file_put_contents($workplace->err(0), 'warned');
    $second = $then->untilNow($workplace);

    expect($first->text($workplace))->toBe('first')
        ->and($second->text($workplace))->toBe(' secondwarned')
        ->and($from->text($workplace))->toBe('')
        ->and(Printed::read(Node::decode(Json::object(...$second->members())->line())))->toEqual($second)
        ->and(Printed::whole($workplace, 0))->toBe('first secondwarned');
});

it('holds nothing of files a worker never wrote', function (): void {
    $workplace = Workplace::at(sprintf('%s/warm', Scratch::directory()));

    expect(Printed::from($workplace, 2)->untilNow($workplace)->text($workplace))->toBe('')
        ->and(Printed::whole($workplace, 2))->toBe('');
});

it('holds only the bytes from where it starts to where it ends, whatever follows, and none where its ends run backwards', function (): void {
    $workplace = Workplace::at(sprintf('%s/warm', Scratch::directory()));
    $workplace->opened(Job::of('', NotGiven::value(), [], NotGiven::value(), []));
    file_put_contents($workplace->out(0), 'first');
    $from = Printed::from($workplace, 0);
    file_put_contents($workplace->out(0), 'X', FILE_APPEND);
    $one = $from->untilNow($workplace);
    file_put_contents($workplace->out(0), ' and what follows', FILE_APPEND);

    expect($one->text($workplace))->toBe('X')
        ->and(Printed::read(Node::decode('{"place": 0, "out": [5, 2], "err": [0, 0]}'))->text($workplace))->toBe('');
});
