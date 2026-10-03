<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Http;

/** The media types of the bodies the gate's requests send. */
enum MediaType: string
{
    case Json = 'application/json';
    case Form = 'application/x-www-form-urlencoded';
    case Gzip = 'application/gzip';
}
