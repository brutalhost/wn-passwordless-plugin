<?php namespace Brutalhost\Passwordless;

use System\Classes\PluginBase;

class Plugin extends PluginBase
{
    /**
     * Component details
     * @return array
     */
    public function componentDetails()
    {
        return [
            'name' => 'brutalhost.passwordless::lang.plugin.name',
            'description' => 'brutalhost.passwordless::lang.plugin.description',
            'icon' => 'wn-icon-key', // Подходит для функциональности "без пароля"
            'homepage' => 'https://github.com/brutalhost/wn-passwordless-plugin'
        ];
    }

    /**
     * Registers components
     * @return array
     */
    public function registerComponents()
    {
        return [
            'Brutalhost\Passwordless\Components\Account' => 'passwordlessAccount'
        ];
    }

    public function registerSettings()
    {
        return [
            'settings' => [
                'label' => 'brutalhost.passwordless::lang.settings.label',
                'description' => 'brutalhost.passwordless::lang.settings.description',
                'icon' => 'wn-icon-key', // Меняем иконку на "замок" для безопасности и настроек
                'category' => 'system::lang.system.categories.users',
                'class' => 'Brutalhost\Passwordless\Models\Settings',
                'order' => 1000
            ]
        ];
    }

    /**
     * Registers mail templates
     * @return array
     */
    public function registerMailTemplates()
    {
        return [
            'brutalhost.passwordless::mail.login' => 'Passwordless login'
        ];
    }

    public function registerSchedule($schedule)
    {
        $schedule->call(function () {
            \Brutalhost\Passwordless\Models\Token::clearExpired();
        })->everyMinute();
    }
}
