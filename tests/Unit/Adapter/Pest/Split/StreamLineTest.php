<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamLine;

it('writes each line a stream records as one line of JSON', function (): void {
    expect([
        StreamLine::parent('T::one', true, 0.5),
        StreamLine::child('m', 'T::one', false, 0.25),
        StreamLine::endedChild('m', 'T::one', -1),
        StreamLine::timedOut('m', 'T::ä/b'),
        StreamLine::fallback('T::one'),
    ])->toBe([
        "{\"k\":\"parent\",\"t\":\"T::one\",\"p\":true,\"s\":0.5}\n",
        "{\"k\":\"child\",\"m\":\"m\",\"t\":\"T::one\",\"f\":false,\"s\":0.25}\n",
        "{\"k\":\"ended\",\"m\":\"m\",\"t\":\"T::one\",\"c\":-1}\n",
        "{\"k\":\"timeout\",\"m\":\"m\",\"t\":\"T::ä/b\"}\n",
        "{\"k\":\"fallback\",\"t\":\"T::one\"}\n",
    ]);
});
