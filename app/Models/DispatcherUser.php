<?php

namespace App\Models;

class DispatcherUser extends User
{
    protected $table = 'dispatchers';

    public static function roleName(): string
    {
        return 'dispatcher';
    }
}
