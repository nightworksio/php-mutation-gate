<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use DOMDocument;
use DOMElement;

use function explode;
use function in_array;
use function is_file;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Format\Xml;
use NightWorksIO\MutationGate\Core\NotGiven;

use function str_starts_with;
use function trim;

/**
 * The first test a JUnit log says failed or errored: the test as the log's
 * `file` attribute names it, its path and its description, and the first
 * line of what PHPUnit said of it, each one plain line. Pest begins what it
 * says of a test with the test's own name, which is left out.
 */
final readonly class FailedFirst
{
    /** What the log puts in a test case that failed an assertion, or errored. */
    private const array OUTCOMES = ['failure', 'error'];

    private function __construct(private string $test, private string $said)
    {
    }

    /** The test the log names first as failed or errored; none where it names none or is not a JUnit log. */
    public static function in(string $log): self|NotGiven
    {
        $document = new DOMDocument();

        if (! is_file($log) || ! $document->load($log, Xml::QUIET)) {
            return NotGiven::value();
        }

        foreach ($document->getElementsByTagName('testcase') as $case) {
            foreach ($case->childNodes as $child) {
                if ($child instanceof DOMElement && in_array($child->tagName, self::OUTCOMES, strict: true)) {
                    return self::of($case, $child->textContent);
                }
            }
        }

        return NotGiven::value();
    }

    public function test(): string
    {
        return $this->test;
    }

    /** The first line of what PHPUnit said of the test. */
    public function said(): string
    {
        return $this->said;
    }

    private static function of(DOMElement $case, string $text): self
    {
        $name = $case->getAttribute('name');
        $message = str_starts_with($text, $name) ? mb_substr($text, mb_strlen($name)) : $text;

        return new self(Fit::plain($case->getAttribute('file')), Fit::plain(explode("\n", trim($message))[0]));
    }
}
