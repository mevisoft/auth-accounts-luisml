<?php

namespace LuisML\AccountsClient\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LuisML\AccountsClient\Actions\EndLocalAccess;
use LuisML\AccountsClient\Http\Controllers\Concerns\LeavesTheApplication;
use LuisML\AccountsClient\Support\SecurityLog;
use Symfony\Component\HttpFoundation\Response;

class AccountsSwitchController extends Controller
{
    use LeavesTheApplication;

    /**
     * Change account: this application's session ends (the central session and the other applications are
     * left alone) and the person goes straight to Accounts' account chooser.
     */
    public function __invoke(Request $request, EndLocalAccess $end): Response
    {
        $ended = $end->handle($request);

        SecurityLog::info('accounts.switched', 'switch', $ended['confirmed'] || $ended['queued'] ? 'success' : 'revocation_failed', $ended['subject']);

        $options = ['prompt' => 'select_account'];

        if (is_string($request->input('login_hint')) && $request->input('login_hint') !== '') {
            $options['login_hint'] = $request->input('login_hint');
        }

        return $this->leaveTo($request, route('accounts.login', $options));
    }
}
