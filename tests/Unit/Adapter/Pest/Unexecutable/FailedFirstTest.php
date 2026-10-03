<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\FailedFirst;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A JUnit log with these test cases, written to a new file. */
function failedFirstLog(string $cases): string
{
    $log = sprintf('%s/junit.xml', Scratch::directory());
    file_put_contents($log, sprintf('<?xml version="1.0"?><testsuites><testsuite name="T">%s</testsuite></testsuites>', $cases));

    return $log;
}

it('names the first test that failed or errored, by its file and description, and the first line PHPUnit said of it', function (): void {
    $log = failedFirstLog(<<<'XML'
        <testcase name="it adds" file="tests/A.php::it adds"/>
        <testcase name="it errs" file="tests/A.php::it errs"><error type="E">it errsRuntimeException: no
        at tests/A.php:5</error></testcase>
        <testcase name="it fails" file="tests/A.php::it fails"><failure type="F">it failsFailed.</failure></testcase>
        XML);

    $failed = FailedFirst::in($log);

    expect($failed)->toBeInstanceOf(FailedFirst::class)
        ->and($failed instanceof FailedFirst ? [$failed->test(), $failed->said()] : [])
        ->toBe(['tests/A.php::it errs', 'RuntimeException: no']);
});

it('keeps what PHPUnit said whole where it does not begin with the test\'s name, as one plain line', function (): void {
    $failed = FailedFirst::in(failedFirstLog("<testcase name=\"it fails\" file=\"tests/A.php::it\tfails\"><failure>  Failed\tasserting.\nat x</failure></testcase>"));

    expect($failed instanceof FailedFirst ? [$failed->test(), $failed->said()] : [])
        ->toBe(['tests/A.php::it fails', 'Failed asserting.']);
});

it('names none where no test failed, there is no log, or the log is not XML', function (): void {
    $notXml = sprintf('%s/junit.xml', Scratch::directory());
    file_put_contents($notXml, 'not xml');

    expect(FailedFirst::in(failedFirstLog('<testcase name="it adds" file="tests/A.php::it adds"/>')))->toEqual(NotGiven::value())
        ->and(FailedFirst::in(sprintf('%s/none.xml', Scratch::directory())))->toEqual(NotGiven::value())
        ->and(FailedFirst::in($notXml))->toEqual(NotGiven::value());
});
