<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/


Artisan::command('tester', function () {
    /** @var \DefStudio\Telegraph\Models\TelegraphBot $bot */
   $bot = \DefStudio\Telegraph\Models\TelegraphBot::find(1);
   $bot->registerCommands([
       'sql' => 'sql kommanda jo\'natish',
       'logs' => 'loglarni yuklab olish',
       'actions' => 'Actionlarni yuklab olish',
       'setConnection' => 'Ulanish uchun bazani tanlang'
   ])->send();
});
