<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Killers;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Off;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use PHPUnit\Event\Facade;

afterEach(function (): void {
    Scratch::sweep();
});

it('names no killer unless the adapter names a results file and Pest a mutated copy', function (): void {
    expect(Killers::listening(results: false, mutated: '/tmp/m', events: new Facade()))->toBe(Off::NamingKillers)
        ->and(Killers::listening('', '/tmp/m', new Facade()))->toBe(Off::NamingKillers)
        ->and(Killers::listening('/r/results.jsonl', mutated: false, events: new Facade()))->toBe(Off::NamingKillers)
        ->and(Killers::listening('/r/results.jsonl', '', new Facade()))->toBe(Off::NamingKillers)
        ->and(Killers::fromEnvironment())->toBe(Off::NamingKillers);
});

it('names no killer where PHPUnit takes no more subscribers', function (): void {
    $sealed = new Facade();
    $sealed->seal();

    expect(Killers::listening('/r/results.jsonl', '/tmp/m', $sealed))->toBe(Off::NamingKillers);
});

it('writes each killer as a line of its own, with the mutated copy it ran on', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $killers = Killers::listening($results, '/tmp/mutations/abc', new Facade());

    if ($killers instanceof Killers) {
        $killers->killedBy('P\Tests\MoneySpec::__pest_evaluable_it_adds');
        $killers->killedBy("Tests\\LegacySpec::testAdds#(1)\xff");
    }

    expect(file_get_contents($results))->toBe(
        "{\"event\":\"killed\",\"mutated\":\"/tmp/mutations/abc\",\"test\":\"P\\\\Tests\\\\MoneySpec::__pest_evaluable_it_adds\"}\n"
        . "{\"event\":\"killed\",\"mutated\":\"/tmp/mutations/abc\",\"test\":\"Tests\\\\LegacySpec::testAdds#(1)\\ufffd\"}\n",
    );
});
