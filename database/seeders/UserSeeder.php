<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $hasUsernameColumn = Schema::hasColumn('users', 'username');

        // Usuario administrador (safe - won't duplicate if exists)
        $adminData = [
            'name' => 'Administrador',
            'password' => Hash::make('Hotel-Wimpy-Administracion-2025#'),
            'security_pin' => '1234',
        ];

        if ($hasUsernameColumn) {
            $adminData['username'] = 'admin';
        }

        $admin = User::updateOrCreate(
            ['email' => 'admin@hotel-wimpy.com'],
            $adminData
        );
        
        if (!$admin->hasRole('Administrador')) {
            $admin->assignRole('Administrador');
        }

        // Usuario recepcionista día (alineado al dominio hotelero)
        $receptionistData = [
            'name' => 'RecepcionistaDia',
            'password' => Hash::make('Recepcionista2025#'),
            'security_pin' => '0000',
            'working_hours' => ['start' => '06:00', 'end' => '14:00'],
        ];

        if ($hasUsernameColumn) {
            $receptionistData['username'] = 'recepcionista.dia';
        }

        $receptionist = User::updateOrCreate(
            ['email' => 'recepcionista.dia@hotel.com'],
            $receptionistData
        );
        
        if (!$receptionist->hasRole('Recepcionista Día')) {
            $receptionist->assignRole('Recepcionista Día');
        }

        // Usuario recepcionista noche
        $nightData = [
            'name' => 'RecepcionistaNocturno',
            'password' => Hash::make('Noche2025#'),
            'security_pin' => '1111',
            'working_hours' => ['start' => '22:00', 'end' => '06:00'],
        ];

        if ($hasUsernameColumn) {
            $nightData['username'] = 'recepcionista.noche';
        }

        $night = User::updateOrCreate(
            ['email' => 'recepcionista.noche@hotel.com'],
            $nightData
        );

        if (!$night->hasRole('Recepcionista Noche')) {
            $night->assignRole('Recepcionista Noche');
        }
    }
}
