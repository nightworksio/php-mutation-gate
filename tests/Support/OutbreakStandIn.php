<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function chmod;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_string;
use function sprintf;

/**
 * A program that stands in for the `outbreak` binary (ADR-0118): it answers
 * `contract` with the contract it is given, and every other command with the
 * answer and the code it is given, keeping what it read on its input and the
 * arguments it was given beside itself.
 */
final readonly class OutbreakStandIn
{
    private const string SCRIPT = <<<'SH'
        #!/bin/sh
        cat > "$0.input"
        printf '%%s' "$*" > "$0.arguments"
        if [ "$1" = contract ]; then
          printf '%%s\n' '%s'
          exit 0
        fi
        printf '%%s\n' '%s'
        exit %d
        SH;

    /** The contract a stand-in answers where it is given none. */
    private const string CONTRACT = '{"outbreak":1,"version":"0.0.0"}';

    private function __construct(private string $binary)
    {
    }

    /** A stand-in in a directory of its own, answering the contract and any other command so. */
    public static function answering(
        string $command,
        int $exit = 0,
        string $contract = self::CONTRACT,
    ): self {
        $binary = sprintf('%s/outbreak', Scratch::directory());
        file_put_contents($binary, sprintf(self::SCRIPT, $contract, $command, $exit));
        chmod($binary, 0o755);

        return new self($binary);
    }

    /** A stand-in answering so, listed as the binary the package ships for this machine in a directory of its own. */
    public static function shipped(string $command, int $exit = 0): self
    {
        $directory = Scratch::directory();
        ShippedOutbreak::listed($directory, ShippedOutbreak::binaryName(), sprintf(self::SCRIPT, self::CONTRACT, $command, $exit));
        $binary = sprintf('%s/%s', $directory, ShippedOutbreak::binaryName());
        chmod($binary, 0o755);

        return new self($binary);
    }

    /** The directory the package ships the stand-in in, where it was shipped. */
    public function shippedIn(): string
    {
        return dirname($this->binary, 2);
    }

    public function binary(): string
    {
        return $this->binary;
    }

    /** What the last command it ran read on its input. */
    public function read(): string
    {
        return $this->kept('input');
    }

    /** The arguments the last command it ran was given, each after a space. */
    public function arguments(): string
    {
        return $this->kept('arguments');
    }

    private function kept(string $what): string
    {
        $kept = file_get_contents(sprintf('%s.%s', $this->binary, $what));

        return is_string($kept) ? $kept : '';
    }
}
