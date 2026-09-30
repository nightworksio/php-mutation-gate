<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use Pest\Mutate\MutationTest;
use ReflectionException;
use ReflectionProperty;

use function sprintf;

use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\Process;

/**
 * What a mutant's own process printed, on both of its streams, as the
 * process that started it holds it. Pest keeps the process to itself, so it
 * is read where Pest keeps it; it is none where Pest started no process, keeps
 * it elsewhere, or kept no output.
 */
final readonly class MutantOutput
{
    /** The property Pest keeps a mutant's own process in. */
    private const string PROCESS = 'process';

    public static function of(MutationTest $test): string
    {
        try {
            $property = new ReflectionProperty(MutationTest::class, self::PROCESS);
            $process = $property->isInitialized($test) ? $property->getValue($test) : null;

            return $process instanceof Process
                ? sprintf('%s%s', $process->getOutput(), $process->getErrorOutput())
                : '';
        } catch (ReflectionException|LogicException) {
            return '';
        }
    }
}
