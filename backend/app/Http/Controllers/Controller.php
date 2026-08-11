<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Laravel 11+ ships a bare base controller. AuthorizesRequests is added back
 * because record-level authorisation via $this->authorize() is mandatory in this
 * project: the permission middleware is only the coarse first gate, and the
 * policy check is what actually prevents IDOR (SEC-AUTHZ-04).
 */
abstract class Controller
{
    use AuthorizesRequests;
}
