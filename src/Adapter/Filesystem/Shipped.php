<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

/** A directory the package ships under its `resources/`. */
enum Shipped: string
{
    /** The CI templates `init --ci` fills in. */
    case Ci = 'ci';

    /** The page and script of the HTML report, as mutation-testing-elements publishes them. */
    case ReportElements = 'mutation-testing-elements';
}
