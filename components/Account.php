<?php

namespace Brutalhost\Passwordless\Components;

use Brutalhost\Passwordless\Models\Settings;
use Cms\Classes\ComponentBase;
use Illuminate\Support\Carbon;
use Winter\Storm\Exception\ApplicationException;
use Brutalhost\Passwordless\Models\Token;
use Cms\Classes\Page;

//use Input;
use Cookie;
use Redirect;
use Validator;
use Mail;
use Response;
use Winter\Storm\Exception\ValidationException;
use Winter\Storm\Support\Facades\Flash;
use Winter\Storm\Support\Facades\Input;
use Winter\Storm\Support\Str;
use Winter\User\Models\User;

class Account extends ComponentBase
{

    /**
     * @var Authentication manager
     */
    protected $auth;

    /**
     * @var Authentication model
     */
    protected $model;

    /**
     * Component details
     * @return array
     */
    public function componentDetails()
    {
        return [
            'name' => 'Account',
            'description' => 'Passwordless login and account manager',
            'graphql' => true
        ];
    }

    /**
     * Register properties
     * @return array
     */
    public function defineProperties()
    {
        return [
            'model' => [
                'title' => 'Auth model',
                'description' => 'User model the form authenticates',
                'type' => 'string',
                'required' => true,
                'default' => 'Winter\User\Models\User'
            ],
            'auth' => [
                'title' => 'Auth provider',
                'description' => 'Class or facade that manages the auth state',
                'type' => 'string',
                'default' => 'Winter\User\Facades\Auth'
            ],
            'mail_template' => [
                'title' => 'Login mail template',
                'description' => 'The mail template that will be send to the user',
                'type' => 'string',
                'default' => 'brutalhost.passwordless::mail.login'
            ],
            'redirect' => [
                'title' => 'Redirect to',
                'description' => 'Page name to redirect to after sign in',
                'type' => 'dropdown',
                'default' => '',
                'graphql' => false
            ],
            'allow_registration' => [
                'title' => 'Allow for registration',
                'description' => 'If disabled, only existing users can request a login',
                'type' => 'checkbox',
                'default' => 0
            ],
            'api' => [
                'title' => 'Enable API',
                'description' => 'Component will expose API endpoint \'?api\' to query the authentication status',
                'type' => 'checkbox',
                'default' => 0,
                'graphql' => false
            ]
        ];
    }

    public function getRedirectOptions()
    {
        return ['' => '- refresh page -', '0' => '- no redirect -'] + Page::sortBy('baseFileName')->lists('baseFileName', 'url');
    }

    public function init()
    {
        $this->auth = $this->property('auth');
        if (!class_exists($this->auth)) {
            throw new ApplicationException(
                "The auth manager '$this->auth' could not be found. " .
                "Please check the component settings."
            );
        }

        $this->model = '\\' . $this->property('model');
        if (!class_exists($this->model)) {
            throw new ApplicationException(
                "The user model '$this->model' could not be found. " .
                "Please check the component settings."
            );
        }
    }

    public function onRun()
    {
        if ($response = $this->api()) {
            return $response;
        }

        if ($response = $this->login()) {
            return $response;
        }

        if ($redirect = Input::get('redirect')) {
            Cookie::queue('passwordless_redirect', $redirect, 60 * 24);
        }

        $this->page['user'] = $this->user();
    }

    public function login()
    {
        if ($this->auth::check()) {
            return false;
        }

        if (!$token = Input('token')) {
            return false;
        }

        try {
            $user = Token::parse($token, true, 'login');
            // Activate only after open link from mail
            if (!$user->is_activated) {
                $user->is_activated = true;
                $user->save();
            }
            $this->auth::login($user);
            $this->page['error'] = false;
            return $this->processRedirects();
        } catch (\Exception $e) {
            $this->page['error'] = $e->getMessage();
        }
    }

    public function processRedirects()
    {
        if ($intended = Cookie::get('passwordless_redirect')) {
            Cookie::queue(Cookie::forget('passwordless_redirect'));
            // make redirection host safe
            $url = parse_url(urldecode($intended));
            return Redirect::to(url($url['path']));
        }

        switch ($default = $this->property('redirect')) {
            case '0':
                break;
            case '':
                return Redirect::to($this->currentPageUrl());
            default:
                return Redirect::to($default);
        }

    }

    public function sendLoginEmail($user, $base_url)
    {
        $expirationTime = Settings::get('token_expiration', 30);

        // Generate token
        $token = Token::generate($user, $expirationTime, 'login');
        $authentication_url = $base_url . '?token=' . $token;
        $email = $user->email;

        $expiresAt = Carbon::now()->addMinutes($expirationTime)->addSecond();
        $timeString = $expiresAt->diffForHumans([
            'parts' => 1,  // Количество отображаемых частей (минуты, часы и т.д.)
            'short' => false,  // Полный формат (minutes, hours)
            'syntax' => Carbon::DIFF_RELATIVE_TO_NOW,  // Относительно текущего времени
            'options' => Carbon::NO_ZERO_DIFF  // Показывать только минуты или часы
        ]);

        // Send invitation email
        Mail::queue(
            $this->property('mail_template'),

            compact('base_url', 'authentication_url', 'timeString'),
            function ($message) use ($email) {
                $message->to($email);
            }
        );
    }

    //
    // Properties
    //

    /**
     * Returns the logged in user, if available
     */
    public function user()
    {
        if (!$this->auth::check()) {
            return null;
        }

        return $this->auth::getUser();
    }


    //
    // API
    //

    public function api()
    {
        if (!$this->property('api')) {
            return false;
        }

        if (!$query = Input::get('api')) {
            return false;
        }

        if (!$this->auth::check()) {
            return response('Unauthorized. ', 401);
        }

        switch ($query) {
            case 'info':
                return $this->apiInfo();
                break;
            default:
                return false;
        }
    }

    public function apiInfo()
    {
        $user = $this->auth::getUser();

        return Response::json([
            'data' => $user
        ]);
    }

    //
    // Ajax
    //

    /**
     * Sends an authentication email to the user
     * @return array October AJAX response
     */
    public function onRequestLogin()
    {
        $rules = ['email' => 'required|email'];
        $validator = Validator::make(Input::only('email'), $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        } else {
            $eventValidatorRules = $this->fireEvent('login_form_rules');
            $eventValidatorRules = array_key_exists(0, $eventValidatorRules) ? $eventValidatorRules[0] : null;

            if (!empty($eventValidatorRules)) {
                $eventValidator = Validator::make(Input::only(array_keys($eventValidatorRules)), $eventValidatorRules);

                if ($eventValidator->fails()) {
                    throw new ValidationException($eventValidator);
                }
            }
        }

        $email = ['email' => $validator->validated()['email']];
        $base_url = $this->currentPageUrl();

        // Get user
        if (!$user = $this->model::where($email)->first()) {
            if ($this->property('allow_registration')) {
                /* @var  $user User */
                $user = new $this->model();
                $user->fill($email);
                $user->name = $user->username = $user->email;
                $user->password = $user->password_confirmation = Str::random($this->model::getMinPasswordLength());
                $user->created_ip_address = Input::ip();
                $user->save();

            } else {
                return ['#passwordless-login-form' => $this->renderPartial('@invited', compact('base_url'))];
            }
        }

        $this->sendLoginEmail($user, $base_url);
        return ['#passwordless-login-form' => $this->renderPartial('@invited', compact('base_url'))];
    }

    /**
     * Signs out
     */
    public function onLogout()
    {
        $this->auth::logout();

        return Redirect::refresh();
    }

    //
    // GraphQL
    //

    public function resolvePasswordlessUser()
    {
        return $this->auth::getUser();
    }

    public function resolvePasswordlessLogout()
    {
        if ($user = $this->auth::getUser()) {
            $this->auth::logout();
            return $user;
        }

        return null;
    }

    public function resolvePasswordlessLoginRequest($root, $args)
    {
        $email = ['email' => $args['email']];
        $validator = Validator::make($email, ['email' => 'required|email']);
        if ($validator->fails()) {
            return 1; // invalid email
        }

        if (!$user = $this->model::where($email)->first()) {
            if ($this->property('allow_registration')) {
                $user = $this->model::create($email);
            } else {
                return 2; // email not registered
            }
        }

        $this->sendLoginEmail($user, url($args['endpoint']));

        return 0; // success
    }

    public function resolvePasswordlessLogin($root, $args)
    {
        try {
            $user = Token::parse($args['token'], true, 'login');
            $this->auth::login($user);
            return $user;
        } catch (\Exception $e) {
            return null;
        }
    }

}
