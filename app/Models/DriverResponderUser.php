<?php

namespace App\Models;

class DriverResponderUser extends User
{
    protected $table = 'drivers';

    public static function roleName(): string
    {
        return 'driver/responder';
    }
}
