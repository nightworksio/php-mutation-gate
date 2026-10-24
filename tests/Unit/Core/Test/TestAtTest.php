<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Test\TestAt;
use NightWorksIO\MutationGate\Core\Test\TestId;

/** A test file whose method `testReadsTheFile` spans lines 7 to 15, a closure on lines 9 to 11 inside it. */
function testAtFile(string $namespace): Contents
{
    return Contents::of(sprintf(<<<'PHP_WRAP'
    <?php
    %s
    use PHPUnit\Framework\TestCase;
    
    final class ReaderTest extends TestCase
    {
        public function testReadsTheFile(): void
        {
            $read = static function (): string {
                return 'read';
            };
    
            $read();
            file_get_contents(__FILE__);
        }
    
        private const NAME = 'reader';
    
        public function opens(): object
        {
            return new class {
                public function read(): void
                {
                }
            };
        }
    }
    PHP_WRAP, $namespace));
}

it('names the test method a line is in, its class fully qualified by the file\'s namespace', function (int $line): void {
    expect(TestAt::in(testAtFile('namespace Tests\\Unit;'), Line::of($line)))
        ->toEqual(TestId::of('Tests\\Unit\\ReaderTest::testReadsTheFile'));
})->with([
    'its first line' => [7],
    'inside a closure in it' => [10],
    'its last statement' => [14],
    'its closing brace' => [15],
]);

it('names a test of a class outside any namespace by its bare name', function (): void {
    expect(TestAt::in(testAtFile(''), Line::of(14)))->toEqual(TestId::of('ReaderTest::testReadsTheFile'));
});

it('names no test for a line in no method, or in a method of an anonymous class', function (int $line): void {
    expect(TestAt::in(testAtFile('namespace Tests;'), Line::of($line)))->toEqual(Nameless::code());
})->with([
    'the class\'s header' => [5],
    'a constant between methods' => [17],
    'a method of an anonymous class' => [23],
]);
