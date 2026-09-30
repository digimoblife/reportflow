<?php

namespace App\Services\Redaction;

/**
 * What kind of secret was found. Only the category and a count are ever kept, never the value.
 */
enum RedactionCategory: string
{
    case ApiKey = 'api_key';
    case Password = 'password';
    case PrivateKey = 'private_key';
    case ConnectionString = 'connection_string';
    case Custom = 'custom';

    /** The input could not be checked safely (PCRE error, invalid UTF-8, too long). */
    case Error = 'redaction_error';
}
