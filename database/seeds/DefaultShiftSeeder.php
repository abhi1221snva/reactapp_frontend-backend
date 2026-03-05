<?php

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DefaultShiftSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $shifts = [
            [
                'user_id' => null,
                'name' => 'Morning Shift',
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
                'grace_period_minutes' => 15,
                'early_departure_minutes' => 15,
                'working_days' => json_encode([1, 2, 3, 4, 5]),
                'break_duration_minutes' => 60,
                'is_default' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'user_id' => null,
                'name' => 'Evening Shift',
                'start_time' => '14:00:00',
                'end_time' => '22:00:00',
                'grace_period_minutes' => 15,
                'early_departure_minutes' => 15,
                'working_days' => json_encode([1, 2, 3, 4, 5]),
                'break_duration_minutes' => 60,
                'is_default' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'user_id' => null,
                'name' => 'Night Shift',
                'start_time' => '22:00:00',
                'end_time' => '06:00:00',
                'grace_period_minutes' => 15,
                'early_departure_minutes' => 15,
                'working_days' => json_encode([1, 2, 3, 4, 5]),
                'break_duration_minutes' => 60,
                'is_default' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'user_id' => null,
                'name' => 'Flexible Hours',
                'start_time' => '08:00:00',
                'end_time' => '20:00:00',
                'grace_period_minutes' => 60,
                'early_departure_minutes' => 60,
                'working_days' => json_encode([1, 2, 3, 4, 5]),
                'break_duration_minutes' => 60,
                'is_default' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now()
            ]
        ];

        DB::table('shifts')->insert($shifts);

        echo "Default shifts created successfully!\n";
    }
}
