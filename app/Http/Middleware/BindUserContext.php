<?php

namespace App\Http\Middleware;

use App\Support\UserContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the UserContext from the signed-in dashboard user (CLAUDE.md rule 11). Runs for page loads and, as a Livewire
 * persistent middleware, for every component update, so a request without a user cannot read user-scoped data
 * (queries fail closed without a context).
 */
class BindUserContext
{
    public function __construct(private readonly UserContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $this->context->set((int) $user->getAuthIdentifier());
        }

        return $next($request);
    }
}
