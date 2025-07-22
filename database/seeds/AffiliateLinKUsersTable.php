<?php

use Illuminate\Database\Seeder;
use App\Model\User;


class AffiliateLinKUsersTable extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $users = \App\Model\User::get()->all();

        foreach ( $users as $user ) {
            $unique_token= $this->generateCode();
            $base_parent_id = $user->base_parent_id;
            if(empty($base_parent_id))
            {
                $base_parent_id = $user->parent_id;
            }

            $client_id = ($base_parent_id);
            $extension = ($user->extension);
            $user_first = User::where('id', $user->id)->get()->first();
            $token_link = '/'.$client_id.'/'.$extension.'/'.$unique_token;
            $user_first->affiliate_link = $token_link;
            $user_first->base_parent_id = $base_parent_id;
            $user_first->save();

        }
    }

    public static function generateCode($length = 35)
    {
        $characters = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++)
        {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }
}
