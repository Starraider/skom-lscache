<?php

declare(strict_types=1);

namespace Skom\Lscache\Http;

/**
 * Enumerates the reasons why a frontend response is cacheable or must bypass LSCache.
 */
enum BypassReason: string
{
    case CACHEABLE = 'cacheable';
    case BACKEND_CONTEXT = 'backend_context';
    case FRONTEND_USER = 'frontend_user';
    case RESPONSE_SET_COOKIE = 'response_set_cookie';
    case REQUEST_METHOD = 'request_method';
    case MISSING_REQUEST_CONTEXT = 'missing_request_context';
    case BYPASS_PATH = 'bypass_path';
    case BYPASS_PLUGIN_SIGNATURE = 'bypass_plugin_signature';
    case BYPASS_CTYPE = 'bypass_ctype';
}
