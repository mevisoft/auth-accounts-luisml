<?php

namespace LuisML\AccountsClient\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use LuisML\AccountsClient\AccountsUnavailable;
use LuisML\AccountsClient\Actions\BeginLogin;
use LuisML\AccountsClient\Actions\CompleteLogin;
use Throwable;

class AccountsCallbackController extends Controller
{
    public function __invoke(Request $request, BeginLogin $begin, CompleteLogin $complete)
    {
        $state = $request->query('state');
        $transaction = !is_string($state) || $state === '' ? null : $begin->consume($request, $state);

        if ($transaction === null) {
            return $this->fail($request, 'La solicitud de acceso caducó o no es válida. Inténtalo de nuevo.');
        }

        if ($request->query('error') !== null) {
            return $this->fail($request, $request->query('error') === 'access_denied'
                ? 'No se concedió el acceso con LuisML.'
                : 'LuisML no pudo completar el acceso. Inténtalo de nuevo.');
        }

        $code = $request->query('code');

        if (!is_string($code) || $code === '') {
            return $this->fail($request, 'LuisML no devolvió un código de acceso.');
        }

        try {
            $complete->handle($request, $code, $transaction);
        } catch (AccountsUnavailable $e) {
            report($e);
            return response()->view('accounts::unavailable', [
                'message' => 'No pudimos contactar a LuisML para completar el acceso. Inténtalo de nuevo.',
                'retryUrl' => route('accounts.login'),
                'safeToRetry' => true,
            ], 503, ['Retry-After' => '10']);
        } catch (Throwable $exception) {
            report($exception);
            Log::warning('Acceso con LuisML rechazado.', [
                'reason' => $exception::class,
                // Only the package's own messages (fixed text): other exceptions may carry data such as SQL bindings.
                'message' => $exception->getMessage(),
            ]);

            return $this->fail($request, 'No pudimos verificar tu identidad. Inténtalo de nuevo.');
        }

        return redirect()->to($transaction['intended'] ?? config('accounts.home'));
    }

    private function fail(Request $request, string $message)
    {
        $request->session()->flash('accounts.error', $message);

        return redirect()->to(config('accounts.home'));
    }
}
