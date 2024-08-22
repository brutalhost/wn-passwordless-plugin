<?php

namespace Brutalhost\Passwordless\Classes;

use Cookie;
use Closure;
use Response;
use Brutalhost\Passwordless\Models\Token;
use Winter\Storm\Exception\ApplicationException;

class CookieTokenAuth
{

    const COOKIE_NAME = 'auth_token';

    /**
     * @param $user
     * @param int $expires Minutes, default = 15 minutes
     */
    public static function login($user, $expires = 15) {
        $token = Token::generate($user, $expires, 'auth');
        Cookie::queue(
            self::COOKIE_NAME,
            $token,
            $expires,
            '/', '',
            env('COOKIE_TOKEN_SECURE', true) ? true : false, // secure
            true // httpOnly
        );
    }

    public static function check() {
        return ! is_null(self::getUser());
    }

    public static function getUser() {
        if (! $token = Cookie::get(self::COOKIE_NAME)) {
            return null;
        }

        try {
            return Token::parse($token, false, 'auth');
        } catch (ApplicationException $e) {
            return null;
        }
    }

    public static function logout() {
        if ($token = Cookie::get(self::COOKIE_NAME)) {
            // invalidate token
            Token::parse($token, true, 'auth');
            Cookie::queue(Cookie::forget(self::COOKIE_NAME));
        }
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        return app(\Illuminate\Cookie\Middleware\EncryptCookies::class)->handle($request, function ($request) use ($next) {
            try {
                if (! $token = Cookie::get(self::COOKIE_NAME)) {
                    throw new ApplicationException('No token provided');
                }
                Token::parse($token, false, 'auth');
            } catch(ApplicationException $e) {
                $message = 'Unauthorized. ' . $e->getMessage();

                if ($request->wantsJson()) {
                    return Response::json(['message' => $message], 401);
                }

                return response($message, 401);
            }

            return $next($request);
        });
    }

}
