<?php

use Illuminate\Database\Seeder;

class ClientSetupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        #seed menu list
        $menuList1 = new \App\Model\Master\MenuList();
        $menuList1->parent_id = 0;
        $menuList1->name = "System Configuration";
        $menuList1->url = "";
        $menuList1->logo = "fa fa-gears";
        $menuList1->status = 1;
        $menuList1->order_menu = 8;
        $menuList1->arrow = 1;
        $menuList1->saveOrFail();

        $userMenu1 = new \App\Model\Master\UserMenu();
        $userMenu1->role_id = 5;
        $userMenu1->menu_id = $menuList1->id;
        $userMenu1->saveOrFail();

        $menuList = new \App\Model\Master\MenuList();
        $menuList->parent_id = $menuList1->id;
        $menuList->name = "Clients";
        $menuList->url = "clients";
        $menuList->logo = "fa fa-building-o";
        $menuList->status = 1;
        $menuList->order_menu = 8;
        $menuList->arrow = 0;
        $menuList->saveOrFail();

        $userMenu = new \App\Model\Master\UserMenu();
        $userMenu->role_id = 5;
        $userMenu->menu_id = $menuList->id;
        $userMenu->saveOrFail();

        $clients = \App\Model\Master\Client::all();
        foreach ( $clients as $client ) {
            $client->stage = \App\Model\Master\Client::MIGRATE_SEED;
            $client->save();
        }
    }
}
