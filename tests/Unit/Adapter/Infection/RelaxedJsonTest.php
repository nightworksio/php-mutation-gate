<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\RelaxedJson;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Node;

it('reads the object a JSON5 text holds, empty objects and fractions as they were written', function (): void {
    $node = RelaxedJson::decode('infection.json5', "{\n  // a comment\n  timeout: 2.0,\n  phpStan: {},\n  path: 'a/b',\n}");

    expect($node instanceof Node ? $node->json() : '')->toBe('{"timeout":2.0,"phpStan":[],"path":"a/b"}');
});

it('cannot judge a text that is not JSON5, holds no object, or holds a number JSON cannot write', function (): void {
    expect(RelaxedJson::decode('infection.json5', '{"source":'))->toEqual(CannotJudge::because(
        'infection.json5 cannot be read, so the gate cannot say what Infection would mutate: '
        . 'Unexpected EOF at line 1 column 11 of the JSON5 data',
    ))->and(RelaxedJson::decode('infection.json', '[1]'))->toEqual(CannotJudge::because(
        'infection.json does not hold an object, so the gate cannot say what Infection would mutate.',
    ))->and(RelaxedJson::decode('infection.json5', '{timeout: Infinity}'))->toBeInstanceOf(CannotJudge::class);
});
