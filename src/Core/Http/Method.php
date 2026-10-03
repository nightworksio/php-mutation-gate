<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Http;

/** The HTTP methods the gate's requests use. */
enum Method: string
{
    case Get = 'GET';
    case Put = 'PUT';
    case Post = 'POST';
}
