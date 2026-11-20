<?php

declare(strict_types=1);
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamEvents;
use NightWorksIO\MutationGate\Tests\Support\FreshProcess;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use PHPUnit\Event\Code\TestDox;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\PreparationErrored;
use PHPUnit\Event\Test\PreparationFailed;
use PHPUnit\Event\TestData\TestDataCollection;
use PHPUnit\Metadata\Metadata;
use PHPUnit\Metadata\MetadataCollection;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * A test method of this name, run in a process of its own or not.
 *
 * @param non-empty-string $method
 */
function streamEventsTest(string $method, bool $isolated = false): TestMethod
{
    return new TestMethod(
        stdClass::class,
        $method,
        __FILE__,
        1,
        new TestDox('T', $method, $method),
        MetadataCollection::fromArray($isolated ? [Metadata::runInSeparateProcess()] : []),
        TestDataCollection::fromArray([]),
    );
}

/**
 * The lines a stream wrote while PHPUnit told it these events, each decoded, its seconds left out: each event the
 * class of a bare one, as the stream reads nothing of an event but the test whose preparation started, or a test
 * whose preparation started, by its method and whether it runs in a process of its own. The stream runs in a fresh
 * PHP process, which holds no descriptor a test would fall back for.
 *
 * @param  list<string|array{string, bool}> $events
 * @return list<array<mixed>>
 */
function streamEventsLines(array $events): array
{
    $root = Scratch::directory();
    $results = sprintf('%s/results.jsonl', $root);
    Scratch::write($root, 'plan.json', (string) json_encode(['results' => $results, 'sites' => []]));

    $answer = json_decode(FreshProcess::answer(sprintf(<<<'CODE'
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Stream;
        use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamEvents;
        use PHPUnit\Event\Code\TestDox;
        use PHPUnit\Event\Code\TestMethod;
        use PHPUnit\Event\Telemetry\Info;
        use PHPUnit\Event\Test\PreparationStarted;
        use PHPUnit\Event\TestData\TestDataCollection;
        use PHPUnit\Metadata\Metadata;
        use PHPUnit\Metadata\MetadataCollection;

        Stream::from(%s, new \NightWorksIO\MutationGate\Adapter\Process\LiveProcesses());

        foreach (%s as $event) {
            $told = is_array($event)
                ? new PreparationStarted(new ReflectionClass(Info::class)->newInstanceWithoutConstructor(), new TestMethod(
                    stdClass::class,
                    $event[0],
                    '/tests/T.php',
                    1,
                    new TestDox('T', $event[0], $event[0]),
                    MetadataCollection::fromArray($event[1] ? [Metadata::runInSeparateProcess()] : []),
                    TestDataCollection::fromArray([]),
                ))
                : new ReflectionClass($event)->newInstanceWithoutConstructor();

            foreach (StreamEvents::subscribers() as $subscriber) {
                $notify = new ReflectionMethod($subscriber, 'notify');
                $type = $notify->getParameters()[0]->getType();

                if ($type instanceof ReflectionNamedType && $type->getName() === $told::class) {
                    $notify->invoke($subscriber, $told);
                }
            }
        }

        Stream::finished();
        $lines = [];

        foreach (is_file(%3$s) ? file(%3$s, FILE_IGNORE_NEW_LINES) : [] as $line) {
            $read = json_decode($line, associative: true);
            unset($read['s']);
            $lines[] = $read;
        }

        echo json_encode($lines);
        CODE, var_export(sprintf('%s/plan.json', $root), return: true), var_export($events, return: true), var_export($results, return: true))), associative: true);

    return is_array($answer) ? array_values(array_filter($answer, is_array(...))) : [];
}

it('fails a test whose preparation, or itself, failed or errored', function (string $failed): void {
    expect(streamEventsLines([['fails', false], $failed, Finished::class]))
        ->toBe([['k' => 'parent', 't' => 'stdClass::fails', 'p' => false]]);
})->with([
    'its preparation errored' => [PreparationErrored::class],
    'its preparation failed' => [PreparationFailed::class],
    'it failed' => [Failed::class],
    'it errored' => [Errored::class],
])->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('forks nothing for a test that runs in a process of its own', function (): void {
    expect(streamEventsLines([['isolated', true], Finished::class, ['shared', false], Finished::class]))->toBe([
        ['k' => 'fallback', 't' => 'stdClass::isolated'],
        ['k' => 'parent', 't' => 'stdClass::isolated', 'p' => true],
        ['k' => 'parent', 't' => 'stdClass::shared', 'p' => true],
    ])->and(StreamEvents::isolates(streamEventsTest('shared')))->toBeFalse();
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');
