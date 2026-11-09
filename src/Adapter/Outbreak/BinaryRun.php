<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

use function fwrite;

use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function rewind;
use function sprintf;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

use function tmpfile;
use function trim;

/**
 * One run of the `outbreak` binary (ADR-0118): started with its input handed
 * as a file, which it reads as fast as it can, and its answer read once it
 * ends, where it ended with nought in its time and answered in the contract.
 *
 * @internal
 */
final readonly class BinaryRun
{
    /** The contract every answer is written in, as `outbreak contract` says it. */
    private const int CONTRACT = 1;

    /** What a message calls the binary's answer. */
    private const string ANSWER = 'Outbreak\'s answer';

    private const string NO_INPUT = 'No file could be made to hand Outbreak its input.';

    private function __construct(private Process $process)
    {
    }

    /**
     * What one run of the binary answered; why not, otherwise.
     *
     * @param non-empty-list<string> $arguments
     */
    public static function ran(string $binary, array $arguments, string $input, Seconds $timeout): Node|NotWritten
    {
        $started = self::started($binary, $arguments, $input, $timeout);

        return $started instanceof self ? $started->answer() : $started;
    }

    /**
     * A run of the binary with these arguments, started with this input; or
     * why it could not be started.
     *
     * @param non-empty-list<string> $arguments
     */
    public static function started(string $binary, array $arguments, string $input, Seconds $timeout): self|NotWritten
    {
        $process = new Process([$binary, ...$arguments], timeout: $timeout->seconds());
        $given = tmpfile();

        if ($given === false) {
            return NotWritten::because(self::NO_INPUT);
        }

        fwrite($given, $input);
        rewind($given);
        $process->setInput($given);

        try {
            $process->start();
        } catch (ExceptionInterface $failure) {
            return NotWritten::because($failure->getMessage());
        }

        return new self($process);
    }

    /** What the run answered, once it ended; why not, otherwise. */
    public function answer(): Node|NotWritten
    {
        try {
            $this->process->wait();
        } catch (ExceptionInterface $failure) {
            return NotWritten::because($failure->getMessage());
        }

        $output = $this->process->getOutput();
        $answer = Node::decode($output, self::ANSWER);

        return $this->process->isSuccessful() && $this->isContract($answer)
            ? $answer
            : NotWritten::because(trim(sprintf(Fit::JOINED, $output, $this->process->getErrorOutput())));
    }

    private function isContract(Node $answer): bool
    {
        try {
            return $answer->field('outbreak')->integer() === self::CONTRACT;
        } catch (NotInShape) {
            return false;
        }
    }
}
