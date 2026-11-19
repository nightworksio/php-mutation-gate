<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\FreshProcess;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * The lines a stream wrote while a test reached a pure expression's site its plan forks a mutant at, each decoded,
 * its seconds left out, and the value the stream's own process ran with: the test fails where the value is the
 * mutated code's. The stream runs in a fresh PHP process, which holds no descriptor a test would fall back for.
 *
 * @return array{list<array<mixed>>, mixed}
 */
function streamValuesRun(): array
{
    $root = Scratch::directory();
    $results = sprintf('%s/results.jsonl', $root);
    Scratch::write($root, 'plan.json', (string) json_encode([
        'results' => $results,
        'sites' => ['money' => ['1' => ['mutant-a', '', []]]],
        'multiple' => 1.0,
        'floor' => 5.0,
        'longest' => 10.0,
    ]));

    $answer = json_decode(FreshProcess::answer(sprintf(<<<'CODE'
        use NightWorksIO\MutationGate\Adapter\Pest\SchemaSwitch;
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Stream;
        use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamEvents;
        use PHPUnit\Event\Code\TestDox;
        use PHPUnit\Event\Code\TestMethod;
        use PHPUnit\Event\Telemetry\Info;
        use PHPUnit\Event\Test\Failed;
        use PHPUnit\Event\Test\Finished;
        use PHPUnit\Event\Test\PreparationStarted;
        use PHPUnit\Event\TestData\TestDataCollection;
        use PHPUnit\Metadata\MetadataCollection;

        $told = static function (object $event): void {
            foreach (StreamEvents::subscribers() as $subscriber) {
                $notify = new ReflectionMethod($subscriber, 'notify');
                $type = $notify->getParameters()[0]->getType();

                if ($type instanceof ReflectionNamedType && $type->getName() === $event::class) {
                    $notify->invoke($subscriber, $event);
                }
            }
        };

        Stream::from(%s);
        $told(new PreparationStarted(new ReflectionClass(Info::class)->newInstanceWithoutConstructor(), new TestMethod(
            stdClass::class,
            'adds',
            '/tests/T.php',
            1,
            new TestDox('T', 'adds', 'adds'),
            MetadataCollection::fromArray([]),
            TestDataCollection::fromArray([]),
        )));
        $value = SchemaSwitch::value('money', 1, static fn(): int => 1 + 2, static fn(): int => 1 - 2);

        if ($value !== 3) {
            $told(new ReflectionClass(Failed::class)->newInstanceWithoutConstructor());
        }

        $told(new ReflectionClass(Finished::class)->newInstanceWithoutConstructor());
        Stream::finished();
        $lines = [];

        foreach (is_file(%2$s) ? file(%2$s, FILE_IGNORE_NEW_LINES) : [] as $line) {
            $read = json_decode($line, associative: true);
            unset($read['s']);
            $lines[] = $read;
        }

        echo json_encode([$lines, $value]);
        CODE, var_export(sprintf('%s/plan.json', $root), return: true), var_export($results, return: true))), associative: true);

    return is_array($answer) && is_array($answer[0] ?? null) ? [array_values(array_filter($answer[0], is_array(...))), $answer[1] ?? null] : [[], null];
}

it('forks a pure expression\'s site whose mutated code gives another value, the child carrying on with the mutated value, which its test fails, and the stream with the original\'s', function (): void {
    [$lines, $value] = streamValuesRun();

    expect($value)->toBe(3)
        ->and($lines)->toContain(['k' => 'child', 'm' => 'mutant-a', 't' => 'stdClass::adds', 'f' => true, 'n' => false])
        ->and($lines)->toContain(['k' => 'parent', 't' => 'stdClass::adds', 'p' => true]);
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');
