<?php

use Illuminate\Database\Seeder;

class RolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $roles = [
            [
                "name" => "system_administrator",
                "status" => 1,
                "level" => "11"
            ],
            
        ];

        foreach ( $roles as $role ) {
            //echo $role["level"];die;
            $master = \App\Model\Role::on("master")->where('level',$role["level"])->get()->first();
            if (empty($master)) {
                echo "Adding {$role["level"]} to master.roles\n";
                $master = new \App\Model\Role([
                    "name" => $role["name"],
                    "status" => $role["status"],
                    "level" => $role["level"]
                ]);
                $master->save();
            }
        }
    }
}
