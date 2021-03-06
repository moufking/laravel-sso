<?php

namespace Brexis\LaravelSSO\Http\Middleware;

use Closure;
use Brexis\LaravelSSO\ServerBrokerManager;
use Brexis\LaravelSSO\Session\ServerSessionManager;
use Brexis\LaravelSSO\Exceptions\InvalidSessionIdException;
use Brexis\LaravelSSO\Events;

use Illuminate\Support\Facades\Auth;

class ServerAuthenticate
{
    protected $broker;

    protected $session;

    public function __construct(ServerBrokerManager $broker, ServerSessionManager $session)
    {
        $this->broker = $broker;
        $this->session = $session;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string|null  $guard
     * @return mixed
     */
    public function handle($request, Closure $next, $guard = null)
    {
        $guard = $guard ?: Auth::guard();
        $sid = $this->broker->getBrokerSessionId($request);

        if (is_null($this->session->get($sid))) {
            return response()->json([
                'code' => 'not_attached',
                'message' => 'Client broker not attached.'
            ], 403);
        }

        try {
            $this->broker->validateBrokerSessionId($sid);

            if ($user = $this->check($guard, $sid, $request)) {
                event(new Events\Authenticated($user, $request));
                return $next($request);
            }

            return response()->json([
                'code' => 'unauthorized',
                'message' => 'Unauthorized.'
            ], 401);
        } catch(InvalidSessionIdException $e) {
            return response()->json([
                'code' => 'invalid_session_id',
                'message' => $e->getMessage()
            ], 403);
        }
    }

    protected function check($guard, $sid)
    {
        $attrs = json_decode($this->session->getUserData($sid), true);

        if (!empty($attrs)) {
            $user = $guard->getProvider()->retrieveByCredentials($attrs);

            if ($user && $guard->onceUsingId($user->id)) {
                return $user;
            }
        }

        return false;
    }
}
