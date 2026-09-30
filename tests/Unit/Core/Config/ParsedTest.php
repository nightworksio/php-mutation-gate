<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Parsed;
use NightWorksIO\MutationGate\Core\Format\Json;

it('writes a date read as a date as YYYY-MM-DD again', function (): void {
    $json = Parsed::json(
        ['ignores' => ['entries' => [['expires' => new DateTimeImmutable('2027-03-31 00:00:00')]]]],
        'mutation-gate.yaml',
    );

    expect($json instanceof Json ? $json->line() : '')
        ->toBe('{"ignores":{"entries":[{"expires":"2027-03-31"}]}}');
});

it('writes a number JSON cannot hold as the text the validator names', function (): void {
    $json = Parsed::json(
        ['mutant' => INF, 'other' => -INF, 'none' => NAN, 'floor' => 1.5],
        'mutation-gate.neon',
    );

    expect($json instanceof Json ? $json->line() : '')
        ->toBe('{"mutant":"INF","other":"-INF","none":"NAN","floor":1.5}');
});

it('keeps plain data as it is', function (): void {
    $json = Parsed::json(
        ['runner' => 'pest', 'trees' => [['path' => 'src', 'floor' => 100]], 'x' => null],
        'mutation-gate.yaml',
    );

    expect($json instanceof Json ? $json->line() : '')
        ->toBe('{"runner":"pest","trees":[{"path":"src","floor":100}],"x":null}');
});

it('refuses an object that is not data, however deep', function (): void {
    expect(Parsed::json(['reports' => [['with' => new ArrayObject()]]], 'mutation-gate.neon'))
        ->toEqual(CannotJudge::because(
            'mutation-gate.neon holds an object, ArrayObject, and a config holds data only.',
        ));
});

it('refuses a date with a time of day', function (DateTimeImmutable $date, string $shown): void {
    expect(Parsed::json(['expires' => $date], 'mutation-gate.yaml'))
        ->toEqual(CannotJudge::because(sprintf(
            'mutation-gate.yaml holds a date with a time, %s; a config date is a day, YYYY-MM-DD.',
            $shown,
        )));
})->with([
    'an hour' => [new DateTimeImmutable('2027-03-31T10:00:00+00:00'), '2027-03-31T10:00:00+00:00'],
    'a second' => [new DateTimeImmutable('2027-03-31T00:00:01+00:00'), '2027-03-31T00:00:01+00:00'],
    'a fraction of a second' => [new DateTimeImmutable('2027-03-31T00:00:00.5+00:00'), '2027-03-31T00:00:00+00:00'],
]);
