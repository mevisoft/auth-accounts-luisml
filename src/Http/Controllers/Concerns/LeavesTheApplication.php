<?php

namespace LuisML\AccountsClient\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

trait LeavesTheApplication
{
    /**
     * Send the browser to an address that is not an Inertia page (Accounts, or a route that ends up
     * there). An Inertia visit is an XHR that would follow a cross-origin redirect and fail, so it
     * is told to make a full visit instead (409 + X-Inertia-Location, Inertia's own protocol).
     */
    protected function leaveTo(Request $request, string $url): Response
    {
        if ($request->header('X-Inertia')) {
            return new Response('', 409, ['X-Inertia-Location' => $url]);
        }

        return new RedirectResponse($url);
    }
}
