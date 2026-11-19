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
            'first_name' => 'Ian Ezekiel',
            'last_name' => 'Capistrano',
            'email' => 'capistrano@gmail.com',
            'password' => Hash::make('123456'),
        ]);

        DispatcherUser::create([
            'first_name' => 'robin',
            'last_name' => 'oncepido',
            'email' => 'oncepido@gmail.com',
            'password' => Hash::make('123456'),
			'phone_number' => '+639922201017',
        ]);

        DriverResponderUser::create([
            'first_name' => 'ronnie',
            'last_name' => 'suscana',
            'email' => 'suscana@gmail.com',
            'password' => Hash::make('123456'),
        ]);

        ResidentUser::create([
            'first_name' => 'Alexander',
            'last_name' => 'Esteban',
            'email' => 'esteban@gmail.com',
            'password' => Hash::make('123456'),
            'registration_status' => 'accepted',
        ]);
    }
}
