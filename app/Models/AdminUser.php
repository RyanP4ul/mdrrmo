<?php

namespace App\Models;

class AdminUser extends User
{
    protected $table = 'admins';

    public static function roleName(): string
    {
        return 'admin';
    }
}
