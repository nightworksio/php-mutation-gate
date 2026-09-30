<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\PestStatus;
use NightWorksIO\MutationGate\Adapter\Pest\PlannedMutant;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
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
        RecordLine::killed('/tmp/mutations/a1', "P\\Tests\\MoneySpec::__pest_evaluable_it_adds"),
        RecordLine::end(),
    ])->toBe([
        '{"event":"planned","id":"a1","file":"/p/src/Money.php","start":11,"end":12,'
        . '"mutator":"Pest\\\\Mutate\\\\Mutators\\\\Arithmetic\\\\PlusToMinus","diff":"- a\\n+ b",'
        . "\"mutated\":\"/tmp/mutations/a1\"}\n",
        "{\"event\":\"made\",\"count\":2,\"opening\":1.0}\n",
        "{\"event\":\"made\",\"count\":0}\n",
        "{\"event\":\"outcome\",\"id\":\"a1\",\"status\":\"untested\"}\n",
        "{\"event\":\"finished\",\"id\":\"a1\",\"status\":\"tested\",\"duration\":2.0}\n",
        "{\"event\":\"killed\",\"mutated\":\"/tmp/mutations/a1\",\"test\":\"P\\\\Tests\\\\MoneySpec::__pest_evaluable_it_adds\"}\n",
        "{\"event\":\"end\"}\n",
    ]);
});
