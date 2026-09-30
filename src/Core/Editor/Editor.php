<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Editor;

/** The editors `init --editor` sets up, by the name it takes (ADR-0015 decision 7). */
enum Editor: string
{
    case VsCode = 'vscode';
}
