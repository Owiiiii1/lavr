<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoogleOAuthSetting extends Model
{
    protected $table = 'google_oauth_settings';

    protected $hidden = [
        'client_secret',
    ];

    protected $fillable = [
        'client_id',
        'client_secret',
        'redirect_uri',
    ];

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
        ];
    }
}
