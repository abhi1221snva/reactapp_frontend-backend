<?php

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Model\Master\Permission;



class MakeUserSystemAdministrator extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $userId= ['73','304','358'];

        $system_administrator = \App\Model\Role::where('level',11)->get()->first(); //11-is system_administrator.
        //echo "<pre>";print_r($system_administrator);die;

        $roleId = $system_administrator->id;
        $level  = $system_administrator->level;


        foreach ( $userId as $id )
        {
            $users = \App\Model\User::findOrFail($id);
            //echo "<pre>";print_r($users);die;
            $users->role = $roleId;
            $users->user_level = $level;
            $users->update();

            $permission_sql = "UPDATE permissions set role = '" . $roleId . "' WHERE user_id =" . $id;
            $record = DB::connection('master')->update($permission_sql);
            echo "Updated userId{$id} to master.permission and master.users\n";

        }
    }
}
