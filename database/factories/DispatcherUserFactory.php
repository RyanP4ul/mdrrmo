<?php

namespace Database\Factories;

use App\Models\DispatcherUser;

/**
 * @extends AccountUserFactory<DispatcherUser>
 */
class DispatcherUserFactory extends AccountUserFactory
{
    protected $model = DispatcherUser::class;
}
