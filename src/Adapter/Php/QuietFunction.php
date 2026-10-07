<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Php;

/**
 * A function a config may call and still read no file: none opens one or
 * calls a callable it is handed. `getenv` reads the environment, which a run
 * reads the base and now in alike.
 */
enum QuietFunction: string
{
    case Abs = 'abs';
    case ArrayKeys = 'array_keys';
    case ArrayMerge = 'array_merge';
    case ArrayUnique = 'array_unique';
    case ArrayValues = 'array_values';
    case Explode = 'explode';
    case Getenv = 'getenv';
    case Implode = 'implode';
    case IsArray = 'is_array';
    case IsInt = 'is_int';
    case IsString = 'is_string';
    case JsonDecode = 'json_decode';
    case JsonEncode = 'json_encode';
    case Max = 'max';
    case Min = 'min';
    case Sprintf = 'sprintf';
    case StrContains = 'str_contains';
    case StrEndsWith = 'str_ends_with';
    case StrReplace = 'str_replace';
    case StrStartsWith = 'str_starts_with';
    case Strtolower = 'strtolower';
    case Strtoupper = 'strtoupper';
    case Trim = 'trim';
}
