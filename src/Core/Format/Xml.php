<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use const LIBXML_NOERROR;
use const LIBXML_NONET;
use const LIBXML_NOWARNING;

/**
 * How the gate reads XML it did not write, such as a project's PHPUnit
 * config or the JUnit log a run wrote: it fetches nothing from the network
 * and prints no error or warning of libxml's, so a file that is not XML
 * reads as none.
 */
final readonly class Xml
{
    /** The libxml options every XML read takes. */
    public const int QUIET = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING;
}
