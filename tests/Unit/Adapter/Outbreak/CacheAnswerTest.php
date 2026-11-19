<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Outbreak\CacheAnswer;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Written;

it('says how many packs the binary moved, under the field it moved them by', function (): void {
    expect(CacheAnswer::of(Node::decode('{"pulled":3}'), 'pull', 'pulled', '/p'))
        ->toEqual(Written::noting('/p', "Outbreak pulled 3 of the proof cache's packs."));
});

it('says why no pack moved, where the binary did not answer', function (): void {
    expect(CacheAnswer::of(NotWritten::because('it is not installed'), 'push', 'pushed', '/p'))
        ->toEqual(NotWritten::because("Outbreak did not push the proof cache's packs: it is not installed"));
});

it('says what the binary answered, where its answer holds no count under the field', function (): void {
    expect(CacheAnswer::of(Node::decode('{"pushed":"three"}'), 'push', 'pushed', '/p'))
        ->toEqual(NotWritten::because('Outbreak did not push the proof cache\'s packs: it answered {"pushed":"three"}'));
});
