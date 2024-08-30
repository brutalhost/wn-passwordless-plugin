<?php

namespace Brutalhost\Passwordless\Models;

use Model;

class Settings extends Model
{
    public $implement = ['System.Behaviors.SettingsModel'];

    // Уникальный код для ваших настроек
    public $settingsCode = 'brutalhost_passwordless_settings';

    // Указываем путь к файлу с описанием полей
    public $settingsFields = 'fields.yaml';
}
