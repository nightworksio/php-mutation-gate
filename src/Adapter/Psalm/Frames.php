<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Psalm;

use NightWorksIO\MutationGate\Core\Format\Bytes;

use function preg_match;
use function sprintf;

/**
 * The Language Server Protocol's base protocol over a stream, as Psalm's
 * language server speaks it: each message a JSON body after a header that
 * gives its length in bytes, `Content-Length: <bytes>`, ended by an empty
 * line.
 */
final readonly class Frames
{
    private const string HEADER = "Content-Length: %d\r\n\r\n%s";

    /** What ends a message's header. */
    private const string HEADER_END = "\r\n\r\n";

    private const string LENGTH = '~^content-length:\s*(\d+)\s*$~mi';

    /**
     * @param list<string> $bodies each whole message's JSON body, in order
     */
    private function __construct(private array $bodies, private string $rest)
    {
    }

    /** A message as the stream carries it. */
    public static function framed(string $json): string
    {
        return sprintf(self::HEADER, Bytes::length($json), $json);
    }

    /** The whole messages at the start of what a stream has carried so far, and what is left of it. */
    public static function read(string $carried): self
    {
        $bodies = [];
        $rest = $carried;

        while (($end = Bytes::find($rest, self::HEADER_END, 0)) !== false) {
            $header = Bytes::slice($rest, 0, $end);
            $start = $end + Bytes::length(self::HEADER_END);
            $length = preg_match(self::LENGTH, $header, $said) === 1 ? (int) $said[1] : 0;

            if (Bytes::length($rest) < $start + $length) {
                break;
            }

            $bodies[] = Bytes::slice($rest, $start, $length);
            $rest = Bytes::from($rest, $start + $length);
        }

        return new self($bodies, $rest);
    }

    /** @return list<string> */
    public function bodies(): array
    {
        return $this->bodies;
    }

    /** What the stream has carried of a message not yet whole. */
    public function rest(): string
    {
        return $this->rest;
    }
}
