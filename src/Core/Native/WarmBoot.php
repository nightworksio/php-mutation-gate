<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Native;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * How a warm worker sets PHP up as a framework does before it runs a test
 * (ADR-0023): the settings and variables the framework's config sets, and
 * the bootstrap it names, which the worker includes.
 *
 * @internal
 */
interface WarmBoot
{
    /** The class whose loading in the boot says it started the framework's events, which each child would inherit. */
    public function events(): string;

    /** PHP set up as the framework's config sets it up; the bootstrap the config names, or none. */
    public function configured(string|NotGiven $config): string|NotGiven;
}
