<?php

use App\Model\Master\Permission;
use App\Model\Role;
use App\Model\User;
use Illuminate\Database\Seeder;

class UserTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        //
        $users = User::all();
        foreach ($users as $user) {
            //echo "Add new permission [".$user->id.",".$user->parent_id.",".$user->role."]\n";  
            $permission = Permission::where(["user_id"=>$user->id, "client_id"=>$user->parent_id])->get()->first()->toArray();
            if (!empty($permission)) {
                $role = Role::where(["id"=>$permission['role']])->get()->first()->toArray();  
               
                echo "Add new level [".$user->id.",".$user->parent_id.",".$role['level']."]\n  ";                
                User::where('id',$user->id)->update(['user_level'=>$role['level']]);
            }
        }
    }
}
