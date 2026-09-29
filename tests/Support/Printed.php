<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function is_string;
use function rewind;
use function stream_get_contents;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/** What a console tester's output printed, read without the tester's own guard. */
final readonly class Printed
{
    public static function by(OutputInterface $output): string
    {
        if (! $output instanceof StreamOutput) {
            return '';
        }

        rewind($output->getStream());
        $printed = stream_get_contents($output->getStream());

        return is_string($printed) ? $printed : '';
    }
}
