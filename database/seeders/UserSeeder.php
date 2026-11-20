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
        AdminUser::firstOrCreate(
            ['email' => 'capistrano@gmail.com'],
            [
                'first_name' => 'Ian Ezekiel',
                'last_name' => 'Capistrano',
                'password' => Hash::make('123456'),
            ],
        );

        DispatcherUser::firstOrCreate(
            ['email' => 'oncepido@gmail.com'],
            [
                'first_name' => 'robin',
                'last_name' => 'oncepido',
                'password' => Hash::make('123456'),
                'phone_number' => '+639922201017',
            ],
        );

        DriverResponderUser::firstOrCreate(
            ['email' => 'suscana@gmail.com'],
            [
                'first_name' => 'ronnie',
                'last_name' => 'suscana',
                'password' => Hash::make('123456'),
            ],
        );

        ResidentUser::firstOrCreate(
            ['email' => 'esteban@gmail.com'],
            [
                'first_name' => 'Alexander',
                'last_name' => 'Esteban',
                'password' => Hash::make('123456'),
                'registration_status' => 'accepted',
            ],
        );
    }
}
