<?php
require_once __DIR__.'/../vendor/autoload.php';

/* For CRM WEBPHONE EXAMPLE*/

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: *');
header('Access-Control-Allow-Headers: *');

/*CLose*/

(new Laravel\Lumen\Bootstrap\LoadEnvironmentVariables(
    dirname(__DIR__)
))->bootstrap();

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| Here we will load the environment and create the application instance
| that serves as the central piece of this framework. We'll use this
| application as an "IoC" container and router for this framework.
|
*/

$app = new Laravel\Lumen\Application(
    dirname(__DIR__)
);

 $app->withFacades();

 $app->withEloquent();

if ($app->environment() === 'local') {
    $app->register(\Flipbox\LumenGenerator\LumenGeneratorServiceProvider::class);
}

/*
|--------------------------------------------------------------------------
| Register Container Bindings
|--------------------------------------------------------------------------
|
| Now we will register a few bindings in the service container. We will
| register the exception handler and the console kernel. You may add
| your own bindings here if you like or you can make another file.
|
*/

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

/*
|--------------------------------------------------------------------------
| Register Middleware
|--------------------------------------------------------------------------
|
| Next, we will register the middleware with the application. These can
| be global middleware that run before and after each request into a
| route or middleware that'll be assigned to some specific routes.
|
*/

// $app->middleware([
//     App\Http\Middleware\ExampleMiddleware::class
// ]);

// $app->routeMiddleware([
//     'auth' => App\Http\Middleware\Authenticate::class,
// ]);

/*
|--------------------------------------------------------------------------
| Register Service Providers
|--------------------------------------------------------------------------
|
| Here we will register all of the application's service providers which
| are used to bind services into the container. Service providers are
| totally optional, so you are not required to uncomment this line.
|
*/

// $app->register(App\Providers\AppServiceProvider::class);
// $app->register(App\Providers\AuthServiceProvider::class);
// $app->register(App\Providers\EventServiceProvider::class);

/*
|--------------------------------------------------------------------------
| Load The Application Routes
|--------------------------------------------------------------------------
|
| Next we will include the routes file so that they can all be added to
| the application. This will provide all of the URLs the application
| can respond to, as well as the controllers that may handle them.
|
*/

$app->router->group([
    'namespace' => 'App\Http\Controllers',
], function ($router) {
    require __DIR__.'/../routes/web.php';
});
$app->withFacades();
$app->withEloquent();
$app->configure('mail');
$app->configure('services');
$app->configure('swagger-lume');
$app->configure('geoip');
$app->configure('cache');
$app->configure('otp');
$app->configure('sms');
$app->register(Illuminate\Mail\MailServiceProvider::class);
$app->register(Sichikawa\LaravelSendgridDriver\MailServiceProvider::class);
$app->register(Maatwebsite\Excel\ExcelServiceProvider::class);
//$app->register(\SwaggerLume\ServiceProvider::class);
//$app->register(\Torann\GeoIP\GeoIPServiceProvider::class);
//$app->register(Illuminate\Redis\RedisServiceProvider::class);

$app->routeMiddleware([
    'jwt.auth' => App\Http\Middleware\JwtMiddleware::class,
    'auth.admin' => App\Http\Middleware\AdminAuth::class,
    'auth.superadmin' => App\Http\Middleware\SuperAdminAuth::class,
    'websiteclient' => App\Http\Middleware\WebSiteClientAuth::class,
    'hasComponent' => App\Http\Middleware\HasComponent::class
]);

if (!class_exists('Redis')) {
    class_alias('Illuminate\Support\Facades\Redis', 'Redis');
}
class_alias('Maatwebsite\Excel\Facades\Excel', 'Excel');
class_alias('Illuminate\Support\Facades\Response', 'Response');
class_alias('Illuminate\Support\Facades\Config', 'Config');
//class_alias('\Torann\GeoIP\Facades\GeoIP', 'GeoIP');
class_alias('Illuminate\Contracts\View\Factory', 'view');
if ($app->environment() !== 'production') {
    $app->register(\Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider::class);
}
date_default_timezone_set(env('APP_TIMEZONE', 'UTC'));
return $app;
