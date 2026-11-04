<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\DispatcherUser;
use App\Models\DriverResponderUser;
use App\Models\ResidentUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        AdminUser::create([
            'first_name' => 'Adm',
            'last_name' => 'In',
            'email' => 'admin@gmail.com',
            'password' => Hash::make('123456'),
        ]);

        DispatcherUser::create([
            'first_name' => 'Dispatcher',
            'last_name' => 'User',
            'email' => 'dispatcher@gmail.com',
            'password' => Hash::make('123456'),
        ]);

        DriverResponderUser::create([
            'first_name' => 'Driver',
            'last_name' => 'Responder',
            'email' => 'driver_responder@gmail.com',
            'password' => Hash::make('123456'),
        ]);

        ResidentUser::create([
            'first_name' => 'Resident',
            'last_name' => 'User',
            'email' => 'resident@gmail.com',
            'password' => Hash::make('123456'),
            'registration_status' => 'accepted',
        ]);
    }
}
