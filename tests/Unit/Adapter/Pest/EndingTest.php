<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Ending;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Silence;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    putenv(GateVariable::Results->value);
    Scratch::sweep();
});

/** An own run that printed this on its output and, with a beat, on its error output, and exited with this code. */
function endedRun(string $output, string $error, int $code): Process
{
    $process = new Process([PHP_BINARY, '-r', sprintf(
        'echo %s; fwrite(STDERR, %s); exit(%d);',
        var_export($output, return: true),
        var_export(sprintf('%s%s', Silence::BEAT, $error), return: true),
        $code,
    )]);
    $process->run();

    return $process;
}

it('records how an own run ended by its mutated copy, its code and that no signal ended it, and nothing it printed', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    putenv(sprintf('%s=%s', GateVariable::Results->value, $results));

    Ending::record(endedRun('a printed secret', 'PHP Fatal error', 2), '/tmp/mutations/abc');

    expect(file_get_contents($results))->toBe("{\"event\":\"ended\",\"mutated\":\"/tmp/mutations/abc\",\"code\":2,\"signalled\":false}\n");
});

it('records nothing where the gate names no results file', function (): void {
    $directory = Scratch::directory();

    Ending::record(endedRun('', '', 1), '/tmp/mutations/abc');
    putenv(sprintf('%s=', GateVariable::Results->value));
    Ending::record(endedRun('', '', 1), '/tmp/mutations/abc');

    expect(glob(sprintf('%s/*', $directory)))->toBe([]);
});
