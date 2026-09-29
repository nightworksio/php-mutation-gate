<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\Config\Parsed;

it('writes a date read as a date as YYYY-MM-DD again', function (): void {
    $document = Parsed::document(
        ['ignores' => ['entries' => [['expires' => new DateTimeImmutable('2027-03-31 00:00:00')]]]],
        'mutation-gate.yaml',
    );

    expect($document instanceof Document ? $document->json() : '')
        ->toBe('{"ignores":{"entries":[{"expires":"2027-03-31"}]}}');
});

it('writes a number JSON cannot hold as the text the validator names', function (): void {
    $document = Parsed::document(
        ['mutant' => INF, 'other' => -INF, 'none' => NAN, 'floor' => 1.5],
        'mutation-gate.neon',
    );

    expect($document instanceof Document ? $document->json() : '')
        ->toBe('{"mutant":"INF","other":"-INF","none":"NAN","floor":1.5}');
});

it('keeps plain data as it is', function (): void {
    $document = Parsed::document(
        ['runner' => 'pest', 'trees' => [['path' => 'src', 'floor' => 100]], 'x' => null],
        'mutation-gate.yaml',
    );

    expect($document instanceof Document ? $document->json() : '')
        ->toBe('{"runner":"pest","trees":[{"path":"src","floor":100}],"x":null}');
});

it('refuses an object that is not data, however deep', function (): void {
    expect(Parsed::document(['reports' => [['with' => new ArrayObject()]]], 'mutation-gate.neon'))
        ->toEqual(CannotJudge::because(
            'mutation-gate.neon holds an object, ArrayObject, and a config holds data only.',
        ));
});
