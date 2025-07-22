<?php

use App\Model\Permission;
use App\Model\User;
use Illuminate\Database\Seeder;

class PermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $users = User::all();
        foreach ($users as $user) {
            $permission = Permission::find(["user_id"=>$user->id, "client_id"=>$user->parent_id]);
            if (empty($permission)) {
                echo "Add new permission [".$user->id.",".$user->parent_id.",".$user->role."]\n";
                $permission = new Permission();
                $permission->user_id = $user->id;
                $permission->client_id = $user->parent_id;
                $permission->role = $user->role;
                $permission->save();
            }
        }
    }
}
