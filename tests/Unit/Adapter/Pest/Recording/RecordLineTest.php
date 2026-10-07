<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\PestStatus;
use NightWorksIO\MutationGate\Adapter\Pest\PlannedMutant;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Placed;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;

it('writes each event as one line of JSON, slashes as they are and whole seconds as seconds', function (): void {
    $planned = PlannedMutant::of(
        'a1',
        DiskPath::of('/p/src/Money.php'),
        Line::of(11),
        Line::of(12),
        PlusToMinus::class,
        "- a\n+ b",
        DiskPath::of('/tmp/mutations/a1'),
    );

    expect([
        RecordLine::planned($planned),
        RecordLine::made(2, Seconds::of(1.0)),
        RecordLine::made(0, Unmeasured::duration()),
        RecordLine::outcome('a1', PestStatus::Untested),
        RecordLine::finished('a1', PestStatus::Tested, 2.0),
        RecordLine::killed('/tmp/mutations/a1', "P\\Tests\\MoneySpec::__pest_evaluable_it_adds", Placed::at(2, 'ab', 9)),
        RecordLine::errored('/tmp/mutations/a1', 'T::adds', Placed::unplaced(9)),
        RecordLine::narrowed('/tmp/mutations/a1', ['/p/tests/AddsSpec.php']),
        RecordLine::preloaded('/tmp/mutations/a1'),
        RecordLine::ran('/tmp/mutations/a1', 3),
        RecordLine::ended('/tmp/mutations/a1', Ended::of(139, signalled: true, printed: 'Segmentation fault')),
        RecordLine::ended('/tmp/mutations/a1', Ended::of(NotGiven::value(), NotGiven::value(), '')),
        RecordLine::end(),
    ])->toBe([
        '{"event":"planned","id":"a1","file":"/p/src/Money.php","start":11,"end":12,'
        . '"mutator":"Pest\\\\Mutate\\\\Mutators\\\\Arithmetic\\\\PlusToMinus","diff":"- a\\n+ b",'
        . "\"mutated\":\"/tmp/mutations/a1\"}\n",
        "{\"event\":\"made\",\"count\":2,\"opening\":1.0}\n",
        "{\"event\":\"made\",\"count\":0}\n",
        "{\"event\":\"outcome\",\"id\":\"a1\",\"status\":\"untested\"}\n",
        "{\"event\":\"finished\",\"id\":\"a1\",\"status\":\"tested\",\"duration\":2.0}\n",
        "{\"event\":\"killed\",\"mutated\":\"/tmp/mutations/a1\",\"test\":\"P\\\\Tests\\\\MoneySpec::__pest_evaluable_it_adds\",\"at\":2,\"order\":\"ab\",\"run\":9}\n",
        "{\"event\":\"errored\",\"mutated\":\"/tmp/mutations/a1\",\"test\":\"T::adds\",\"run\":9}\n",
        "{\"event\":\"narrowed\",\"mutated\":\"/tmp/mutations/a1\",\"files\":[\"/p/tests/AddsSpec.php\"]}\n",
        "{\"event\":\"preloaded\",\"mutated\":\"/tmp/mutations/a1\"}\n",
        "{\"event\":\"ran\",\"mutated\":\"/tmp/mutations/a1\",\"count\":3}\n",
        "{\"event\":\"ended\",\"mutated\":\"/tmp/mutations/a1\",\"code\":139,\"signalled\":true,\"printed\":\"Segmentation fault\"}\n",
        "{\"event\":\"ended\",\"mutated\":\"/tmp/mutations/a1\",\"printed\":\"\"}\n",
        "{\"event\":\"end\"}\n",
    ]);
});

it('fails rather than write a record JSON cannot hold, which would read as a line cut short', function (): void {
    expect(static fn(): string => RecordLine::finished('a1', PestStatus::Tested, NAN))->toThrow(JsonException::class);
});

it('writes a status the plugin does not know as Pest names it, for the adapter to refuse', function (): void {
    expect(RecordLine::outcome('a1', 'resurrected'))->toBe("{\"event\":\"outcome\",\"id\":\"a1\",\"status\":\"resurrected\"}\n")
        ->and(RecordLine::finished('a1', 'resurrected', 1.0))
        ->toBe("{\"event\":\"finished\",\"id\":\"a1\",\"status\":\"resurrected\",\"duration\":1.0}\n");
});
