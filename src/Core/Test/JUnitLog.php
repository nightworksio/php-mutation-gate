<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

/** The JUnit log a runner has PHPUnit write into the directory the gate gives it, which the gate reads back. */
final readonly class JUnitLog
{
    public const string NAME = 'junit.xml';
}
