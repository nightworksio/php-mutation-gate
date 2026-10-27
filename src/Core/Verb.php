<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core;

/** A command of the gate that the gate names in what it writes or prints for itself. */
enum Verb: string
{
    case Run = 'run';
    case Plan = 'plan';
    case Verdict = 'verdict';
    case Baseline = 'baseline';
    case Reproduce = 'reproduce';
    case Explain = 'explain';
    case Stub = 'stub';
    case Triage = 'triage';
    case Watch = 'watch';
    case Digest = 'digest';
    case Migrate = 'migrate';
    case Coverage = 'coverage';
    case ConfigShow = 'config:show';
    case Init = 'init';
    case Doctor = 'doctor';
    case Hook = 'hook';
    case PestPatch = 'pest:patch';
    case InfectionPatch = 'infection:patch';
}
