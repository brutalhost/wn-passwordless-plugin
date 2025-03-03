# Passwordless Login
This plugin brings passwordless user authentication to WinterCMS.
Instead of filling username and password, frontend users provide their email address and receive a link with login
token that registers and authenticates them on the site. Passwordless authentication is
[secure](https://auth0.com/blog/is-passwordless-authentication-more-secure-than-passwords/)
and can greatly improve the user experience as it simplifies the registration and login process where many users
dread having to fill out forms and go through a rigorous registration process.

The plugin is based in [October's Passwordless Login](https://github.com/nocio/oc-passwordless-plugin/blob/master/models/Token.php)
with slight adaptations.

# Brutalhost - new features by me
- Supports the latest version of Winter CMS, PHP 8 and above
- A settings page has been added, allowing you to specify the lifespan of the token sent via email link.
- You can add additional fields to the form that will be processed after the email is validated. This is primarily useful for implementing CAPTCHA. For example:
```php
use Brutalhost\YandexSmartcaptcha\Classes\Rules\YandexCaptcha;

function onInit()
{
    $this['passwordlessAccount']->bindEvent('login_form_rules', function () {
        return ['smart-token' => ['required', new YandexCaptcha]];
    });
}
```
- The email content has been modified (the token lifetime is automatically substituted from the settings).


## Features

- Works well with Winter.User plugin as well as custom authentication systems
- Login tokens are valid only once and expire after 30 minutes for increased security while being automatically cleaned from the system
- Supports redirection after login
- Optional JSON API to consume user details
- Open source to allow and encourage security inspection
- Developer friendly and highly customizable
- Includes a cookie-token authentication method that can minimize repeated logins

If you find this plugin useful, please consider donating to support its further development.

## Installation
For the time being, use composer to install:
```
composer require brutalhost/wn-passwordless-plugin
php artisan winter:up
```

## The Account component


The plugin provides an *Account* component that is similiar to Winter.User's account component.
The account component provides the main functionality and can be included in any CMS page that should serve as login endpoint.

The Account component has the following properties:

* `model` - [string] specifies the user model the form authenticates (must have an email field). Defaults to `Winter\User\Models\User`
* `auth` - [string] specifies a class of facade that manages the authentication state (see below). Defaults to `Winter\User\Facades\Auth`
* `mail_template` [string] the email template of the login mail. Defaults to `brutalhost.passwordless::mail.login`
* `redirect` [dropdown] specifies a page name to redirect to after sign in (can be overwritten, see below)
* `allow_registration` - [checkbox] if disabled, only existing users can request a login
* `api` - [checkbox] if enabled, the component will expose an API endpoint ``?api`` to query the authentication status

The component will display the email login form and -- if the user is logged in -- display account information. Note that the component requires the ajax framework to work.

## The authentication manager

The authentication manager that can be specified in the Account component is a class or facade that manages the user authentication through a standardised API:

- ``login($user) {}`` - signs in `$user`
- ``check() {}`` - checks whether user is authenticated (returns boolean)
- ``getUser() {}`` - returns the authenticated user or ``null`` if not authenticated
- ``logout() {}`` - logs the user out

Available auth managers:

*Winter\User\Facades\Auth*

Auth manager provided by the Winter.User plugin.

*Brutalhost\Passwordless\Classes\CookieTokenAuth*

Auth manager that stores authentication state as token in a httpOnly cookie. The user stays authenticated until the cookie is deleted or the token expires. The manager can be used as middleware to protect endpoints that require authentication. The authentication method is particuarly useful for RESTful APIs. Note that the cookie will only be transfered via secure https connections. If you want to allow http connections you can set ``COOKIE_TOKEN_SECURE=true`` in the ``.env`` file.

*Custom manager*

To cater for custom authentication mechanism you can implement your own auth manager that exhibits the given API. If you implemented an auth manager that could be useful to others please consider contributing it in a pull request.

### Return redirections

The Account manager can process ``GET`` redirect requests after login, e.g. ``?redirect=/awesome/redirect/url``. This can be useful to improve the user expirience in the case in which an unauthenticated user accesses a page that requires authentication and is being redirected to the login page. Using ``GET``, the original request location can be stored for after login so that the user is automatically redirected to the page she originally intended to access. Note that GET-redirects overwrite the redirection behaviour that can be defined in the component settings.

### JSON API

If enabled, the Account component exposes and JSON API endpoint that can be consumed by an authenticated user (unauthenticated users will face an 401 Unauthorized. response). Currently, the only route is ``?api=info`` which returns the jsonified user model.

### Developer API

The plugin provides a token model ``Brutalhost\Passwordless\Models\Token`` that can be re-used by developers to implement token management. The model provides two main functions:

- ``generate($user, $expires = null, $scope = 'default')`` which returns a token for a given ``$user`` model object (or more general the token payload). Make sure to specificy a custom scope, e.g. `myplugin` to avoid scope collision.
- ``parse($raw_token, $delete = false, $scope = null)`` which parses (and optionally ``$delete``s) a provided ``$raw_token`` in a given ``$scope`` and returns the correspoding ``$user`` model. It raises an ``ApplicationException`` if the token is expired, invalid or cannot be parsed.

More details can be found in the [model class documentation](https://github.com/helmutkaufmann/wn-passwordless-plugin/blob/master/models/Token.php).
