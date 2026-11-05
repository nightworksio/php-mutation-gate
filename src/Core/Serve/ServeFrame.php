<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Serve;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * One line of JSON a daemon of `mutation-gate serve` answers a client with
 * (ADR-0078): text its command wrote to its output or to its error output,
 * the command's exit code, which ends the answer, or why the daemon
 * refused the request, which ends it too.
 *
 * @internal
 */
final readonly class ServeFrame
{
    private function __construct(private ServeStream $stream, private string $text, private int $code)
    {
    }

    /** Text the command wrote to this stream. */
    public static function written(ServeStream $stream, string $text): self
    {
        return new self($stream, $text, 0);
    }

    /** The command's exit code, which ends the answer. */
    public static function exited(int $code): self
    {
        return new self(ServeStream::Exit, '', $code);
    }

    /** Why the daemon refused the request, which ends the answer; the client then runs the command itself. */
    public static function refused(string $why): self
    {
        return new self(ServeStream::Refusal, $why, 0);
    }

    /** The frame on this line; or none, where the line is not one. */
    public static function readFrom(string $line): self|NotGiven
    {
        try {
            $at = Node::decode($line);
            $stream = ServeStream::tryFrom($at->field('stream')->text());

            return $stream instanceof ServeStream
                ? new self($stream, $at->field('text')->text(), $at->field('code')->integer())
                : NotGiven::value();
        } catch (NotInShape) {
            return NotGiven::value();
        }
    }

    public function stream(): ServeStream
    {
        return $this->stream;
    }

    public function text(): string
    {
        return $this->text;
    }

    /** The exit code an exit frame carries. */
    public function code(): int
    {
        return $this->code;
    }

    /** The frame on one line of JSON, ended by a newline. */
    public function line(): string
    {
        $json = Json::object(
            Member::of('stream', $this->stream->value),
            Member::of('text', $this->text),
            Member::of('code', $this->code),
        );

        return sprintf("%s\n", $json->line());
    }
}
