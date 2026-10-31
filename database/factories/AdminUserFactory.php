<?php

namespace Database\Factories;

use App\Models\AdminUser;

/**
 * @extends AccountUserFactory<AdminUser>
 */
class AdminUserFactory extends AccountUserFactory
{
    protected $model = AdminUser::class;
}
