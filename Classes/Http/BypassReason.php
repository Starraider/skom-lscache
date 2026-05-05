<?php

declare(strict_types=1);

namespace Skom\Lscache\Http;

enum BypassReason: string
{
    case CACHEABLE = 'cacheable';
    case BACKEND_CONTEXT = 'backend_context';
    case FRONTEND_USER = 'frontend_user';
    case RESPONSE_SET_COOKIE = 'response_set_cookie';
    case REQUEST_METHOD = 'request_method';
    case BYPASS_PATH = 'bypass_path';
    case BYPASS_PLUGIN_SIGNATURE = 'bypass_plugin_signature';
    case BYPASS_CTYPE = 'bypass_ctype';
}
