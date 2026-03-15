<?php

/*
  |--------------------------------------------------------------------------
  | Application Routes
  |--------------------------------------------------------------------------
  |
  | Here is where you can register all of the routes for an application.
  | It is a breeze. Simply tell Lumen the URIs it should respond to
  | and give it the Closure to call when that URI is requested.
  |
 */
// ─── Public: Affiliate Apply Form + Merchant Portal ──────────────────────────
// These routes require NO authentication — accessed by external applicants
$router->group(['middleware' => ['throttle:60,1']], function () use ($router) {
    $router->get('public/apply/{code}',                  'PublicApplicationController@getApplyForm');
    $router->post('public/apply/{code}',                 'PublicApplicationController@submitApplication');
    $router->get('public/apply/{token}/pdf',             'PublicApplicationController@renderApplicationPdf');
    $router->get('public/merchant/{token}',              'PublicApplicationController@getMerchantPortal');
    $router->post('public/merchant/{token}',             'PublicApplicationController@updateMerchant');
    $router->post('public/merchant/{token}/upload',      'PublicApplicationController@uploadDocument');

    // Tenant company logo — public (shown on apply forms, PDFs, etc.)
    $router->get('public/tenant/{clientId}/logo',        'TenantFileController@serveLogo');

    // Lead document serve — validated by lead_token ownership
    $router->get('public/lead/{token}/document/{docId}', 'PublicApplicationController@serveDocument');
});

$router->get('/list-all-cache', 'RedisCacheController@listAllCache');
$router->get('/cache-detail', 'RedisCacheController@getCacheDetail');
$router->get('/cache-detail/{key}', 'RedisCacheController@getCacheDetail');
$router->post('/delete-cache', 'RedisCacheController@deleteCache');
$router->delete('/delete-cache', 'RedisCacheController@deleteCache');
$router->post('/delete-cache-by-age', 'RedisCacheController@deleteCacheByAge');
$router->get('/test-redis', 'RedisCacheController@testConnection');

$router->get('/trigger-pusher-test', 'PusherController@triggerTest');
$router->get('/test-fcm-trigger', 'NotificationController@testFcmTrigger');




// Rate limited: 10 login attempts per minute per IP
$router->group(['middleware' => ['throttle:10,1']], function () use ($router) {
    $router->post('authentication', 'AuthenticationController@authentication');
});

$router->group([
    'prefix' => 'v2',
    'middleware' => 'easify.appkey'
], function () use ($router) {

  $router->post('/credential/create', 'AuthenticationController@createCredential');
  $router->post('/credential/delete', 'AuthenticationController@deleteCredential');
  $router->post('/delete-user', 'AuthenticationController@deleteUser');
  $router->post('/phone-number/create', 'AuthenticationController@createPhoneNumber');
  $router->post('/phone-number/update', 'AuthenticationController@updatePhoneNumber');
  $router->post('/phone-number/delete', 'AuthenticationController@deletePhoneNumber');


  
});
$router->post('v2/login', 'AuthenticationController@loginv2');
  $router->post('v2/register', 'AuthenticationController@createUser');
$router->post('v2/validate-email', 'AuthenticationController@checkEmail');


//$router->group(['middleware' => 'easify.appkey'], function () use ($router) {

    //$router->post('/register', 'AuthController@register');
//$router->POST('authentication', 'AuthenticationController@authentication');
    
    //$router->post('/validate-email', 'AuthController@validateEmail');

//});




// ─── System Health & Observability ───────────────────────────────────────────
$router->group(['middleware' => ['jwt.auth', 'audit.log']], function () use ($router) {
    $router->get('system/health',              'SystemHealthController@health');
    $router->get('system/queue-stats',         'SystemHealthController@queueStats');
    $router->get('system/error-trends',        'SystemHealthController@errorTrends');
    $router->get('system/performance-metrics', 'SystemHealthController@performanceMetrics');
});

// ─── Server Monitoring + Swagger (level 11 — system_administrator only) ──────
$router->group(['middleware' => ['jwt.auth', 'auth.sysadmin']], function () use ($router) {
    $router->get('system/server-info',         'SystemServerController@serverInfo');

    // Swagger / OpenAPI documentation — system_administrator only
    $router->get('docs',                       'SwaggerController@spec');
    $router->get('api/documentation',          'SwaggerController@spec');
    $router->post('system/swagger-regenerate', 'SwaggerController@regenerate');
});

$router->get('/redis-test', function () {
    externalRedisCacheSet(123, 'test-prompt', ['data' => 'value from Redis!']);
    return externalRedisCacheGet(123, 'test-prompt', 'Not found');
});

$router->get('/test-timezone', 'TimezoneTestController@test');
$router->get('/', function () use ($router) {
  return $router->app->version();
});
$router->get('receiver-fax', 'FaxController@receiverFax');
$router->post('receiver-fax', 'FaxController@receiverFax');

//login
$router->POST('authentication_copy', 'AuthenticationController@authentication_copy');

$router->POST('verify_google_otp', 'TwoFactorController@verify_google_otp');

// ─── TOTP 2FA Routes ─────────────────────────────────────────────────────────
$router->group(['middleware' => ['throttle:10,1']], function () use ($router) {
    // No JWT auth — called after partial login when 2FA is required
    $router->post('2fa/verify', 'TwoFactorAuthController@verify');
});

$router->group(['middleware' => ['jwt.auth', 'throttle:10,1']], function () use ($router) {
    $router->get('2fa/status',                   'TwoFactorAuthController@status');
    $router->post('2fa/setup',                   'TwoFactorAuthController@setup');
    $router->post('2fa/enable',                  'TwoFactorAuthController@enable');
    $router->post('2fa/disable',                 'TwoFactorAuthController@disable');
    $router->post('2fa/backup-codes/regenerate', 'TwoFactorAuthController@regenerateBackupCodes');
    // JWT token revocation — blacklists the token in Redis
    $router->post('logout', 'AuthenticationController@logout');
});
//$router->POST('authentication_copy', 'AuthenticationController@authentication_copy');
// $router->get('auth/google/redirect', 'GoogleController@redirectToGoogle');
$router->post('auth/google/callback', 'GoogleController@handleGoogleCallback');
$router->post('auth/twitter/callback', 'TwitterController@handleTwitterCallback');

//cron job
$router->get('add-lead-temp', 'CronController@addLeadTemp');
$router->get('cron-email', 'CronController@cronEmail');


//pusher
$router->post('check-and-get-user-id-for-pusher', 'PusherController@checkAndGetUserIdForPusher');
// ─── Legacy Registration V1 (Rate-limited: 10 requests per minute per IP) ──
$router->group(['middleware' => ['throttle:10,1']], function () use ($router) {
    $router->post('prospect/register', 'RegisterController@saveInitialData');
    $router->post('prospect/resend', 'RegisterController@resendOtp');
    $router->post('prospect/verify', 'RegisterController@verifyOtp');
    $router->post('prospect/sendotp/mobile', 'RegisterController@sendOtpMobile');
    $router->post('prospect/resend/mobile', 'RegisterController@resendOtpMobile');
    $router->post('prospect/verify/mobile', 'RegisterController@verifyOtpMobile');
});

// ─── Multi-step Registration V2 ─────────────────────────────────────────────
// Rate-limited: 10 requests per minute per IP
$router->group(['middleware' => ['throttle:10,1']], function () use ($router) {
    // Step 1 — Account details (name, business_name, password)
    $router->post('register/init', 'RegistrationController@init');

    // Step 2 — Email OTP
    $router->post('register/email/send-otp',   'RegistrationController@sendEmailOtp');
    $router->post('register/email/verify-otp', 'RegistrationController@verifyEmailOtp');

    // Step 3 — Phone OTP + final user + client DB creation
    $router->post('register/phone/send-otp',   'RegistrationController@sendPhoneOtp');
    $router->post('register/phone/verify-otp', 'RegistrationController@verifyPhoneOtp');

    // Google OAuth registration — verifies token, skips email OTP
    $router->post('register/google', 'RegistrationController@googleRegister');
});


#Routes with super admin rights should be added here
$router->group(['middleware' => ['jwt.auth', 'auth.superadmin', 'audit.log']], function () use ($router) {

  // ── Admin Client Management ─────────────────────────────────────────────
  $router->get('admin/clients',                   'AdminClientController@index');
  $router->get('admin/clients/{id}',              'AdminClientController@show');
  $router->post('admin/clients',                  'AdminClientController@store');
  $router->put('admin/clients/{id}',              'AdminClientController@update');
  $router->post('admin/clients/{id}/activate',    'AdminClientController@activate');
  $router->post('admin/clients/{id}/deactivate',  'AdminClientController@deactivate');
  $router->post('admin/clients/{id}/switch',      'AdminClientController@switchTo');

  #create client
  $router->put('client', 'ClientController@create');
  $router->get('clients', 'ClientController@index');
  $router->get('client/{id}', 'ClientController@show');
  $router->post('client/manual-subscription', 'ClientController@performManualSubscription');
  Route::post('client/credit-wallet', 'ClientController@creditWallet');
  $router->post('client/{id}', 'ClientController@update');

  //sms providers


  $router->get('api-logs', 'ApiLogsController@index');
  $router->post('api-logs-data', 'ApiLogsController@getLogs');

  //packages
  $router->put('package', 'SubscriptionController@create');
  $router->get('package/{key}', 'SubscriptionController@show');
  $router->post('package/{key}', 'SubscriptionController@update');

  //module
  $router->put('module', 'ModuleController@create');
  $router->get('module/{key}', 'ModuleController@show');
  $router->post('module/{key}', 'ModuleController@update');

  //component
  $router->get('sub-menu', 'ModuleComponentController@subMenu');
  $router->get('parent-menu', 'ModuleComponentController@parentMenu');

  $router->get('modules', 'ModuleController@index');
  $router->get('components', 'ModuleComponentController@index');

  //country wise rate

  $router->get('country-wise-rate/{key}', 'ModuleController@rate');
  $router->put('add-rate', 'ModuleController@createRate');
  $router->get('rate/{id}', 'ModuleController@showRate');

  $router->post('rate/{id}', 'ModuleController@updateRate');
});


#Routes with admin rights should be added here
$router->group(['middleware' => ['jwt.auth', 'audit.log']], function () use ($router) {

  Route::get('/api/emails', 'EmailController@index');
  Route::get('/api/emails/{id}', 'EmailController@show');
  Route::post('/api/emails/draft', 'EmailController@storeDraft');
  Route::put('/api/emails/draft/{id}', 'EmailController@updateDraft');
  Route::delete('/api/emails/draft/{id}', 'EmailController@deleteDraft');
  Route::post('/api/emails/archive', 'EmailController@archive');
  Route::post('/api/emails/unarchive', 'EmailController@unarchive');
  #create user
  $router->put('user', 'ExtensionController@saveNewExtension');

  #User permissions
  $router->get('user/{userId}/permission', 'UserController@showPermission');
  $router->put('user/{userId}/permission', 'UserController@addPermission');
  $router->post('user/{userId}/permission', 'UserController@updatePermission');
  $router->post('user/permission', 'UserController@updatePermissionNew');
  $router->delete('user/{userId}/permission', 'UserController@removePermission');
  $router->post('user/{userId}/assignable-roles', 'UserController@assignableRoles');
  $router->post('user/assignable-roles', 'UserController@assignableRolesNew');

  $router->post('user/{userId}/super-admin-permission', 'UserController@updatePermissionSuperAdmin');
  $router->get('user/{userId}/user-permission', 'UserController@userPermission');
  $router->get('user/selected', 'UserController@getSelectedUsers');

  $router->get('/prompt-voices', 'VoiceController@getVoices');

  //email tempaltes
  $router->put('email-template', 'EmailTempleteController@create');
  $router->post('email-template/{id}', 'EmailTempleteController@update');
  $router->delete('email-template/{id}', 'EmailTempleteController@delete');
  $router->post('delete-email-templete', 'EmailTempleteController@deleteStatus');


  // ip setting
  $router->post('ip/query-ip-whitelist', 'IpSettingController@queryIpWhitelist');
  $router->post('ip/approve', 'IpSettingController@approveIp');
  $router->post('ip/reject', 'IpSettingController@rejectIp');
  $router->post('ip/whitelist-ip', 'IpSettingController@whitelistIpOnServers');

  //Extension-Group Modify Operations
  $router->put('extension-group', 'GroupController@add');
  $router->patch('extension-group/{id}', 'GroupController@patch');
  $router->patch('extension-group-update', 'GroupController@patchNew');

  $router->delete('extension-group/{id}', 'GroupController@delete');
  $router->delete('extension-group-delete', 'GroupController@deleteNew');
  $router->post('extension/deleteFromGroup', 'GroupController@deleteExtensionFromGroup');

  $router->post('status-update-group', 'GroupController@updateGroupStatus');

  //subscription packages
  $router->get('active-client-plans', 'ClientPackagesController@activeClientPlans');
  $router->get('history-client-plans', 'ClientPackagesController@historyClientPlans');

  $router->post('dialer-all-count', 'DialerAllCountController@index');


  #for view the client details
  $router->get('client/{id}', 'ClientController@show');
});

############Merchant's routes
$router->POST('merchant-add', 'Merchant\AuthController@add');
$router->POST('merchant-auth', 'Merchant\AuthController@login');
$router->POST('merchants', 'Merchant\AuthController@get');
##########Merchant's routes ends

// Team Chat Widget Public Routes (No Auth Required)
$router->get('team-chat/widget/validate', 'TeamChatWidgetController@validateToken');

$router->group(['middleware' => ['jwt.auth', 'audit.log', 'tenant']], function () use ($router) {
  // $router->post('auth/google/callback', 'UserMailController@googlecallback');
  //profile
  $router->get('profile', 'ProfileController@index');
  $router->post('/profile/upload-avatar', 'UserController@uploadAvatar');
  $router->post('/profile/update-two-factor', 'ProfileController@updateTwoFactor');
  $router->post('/profile/update-google-auth', 'ProfileController@updateGoogleAuthenticator');
  $router->post('verify-google_otp', 'ProfileController@verifyGoogleAuthenticator');
  //Extension-Group Read Operations
  $router->get('extension-group', 'GroupController@list');
  $router->get('extension-group/{id}', 'GroupController@show');
  $router->get('extension-group-map', 'ExtensionGroupMapController@index');

  $router->put('sms-provider/{id}', 'ClientController@createSmsProvider');

  $router->get('sms-provider/{id}', 'ClientController@showSmsProvider');

  //smtp setting
  $router->get('smtps', 'SmtpController@index');
  $router->put('smtp', 'SmtpController@create');
  $router->get('smtp/{id}', 'SmtpController@show');
  $router->post('smtp/{id}', 'SmtpController@update');
  $router->delete('smtp/{id}', 'SmtpController@delete');
  $router->get('smtp/type/{senderType}', 'SmtpController@query');

  //sms setting
  $router->get('sms-setting',      'SmsSettingController@index');
  $router->put('setting-sms',      'SmsSettingController@create');
  $router->get('setting-sms/{id}', 'SmsSettingController@show');
  $router->post('setting-sms/{id}', 'SmsSettingController@update');
  $router->delete('sms-delete/{id}', 'SmsSettingController@delete');

  //email tempaltes
  $router->get('email-templates', 'EmailTempleteController@index');
  $router->get('email-template/{id}', 'EmailTempleteController@show');

  $router->delete('email-template/{id}', 'EmailTempleteController@delete');
  $router->post('status-update-email-template', 'EmailTempleteController@updateEmailTemplateStatus');


  $router->post('user-detail', 'UserController@userDetail');
  $router->post('update-logo', 'UserController@updateLogo');
  $router->post('update-email-setting', 'UserController@updateEmailSetting');

  $router->post('user-setting', 'UserController@userSetting');
  $router->post('update-profile', 'UserController@userProfileUpdate');
  $router->post('change-dialer-mode-extension', 'UserController@changeDialerModeExtension');

  $router->post('update-timezone', 'UserController@userUpdateTimezone');
  $router->post('update-user-password', 'UserController@updateUserPassword');

  $router->post('/switch-client/{clientId}', 'UserController@switchClient');

  $router->post('delete-voicemail', 'UserController@deleteVoicemail');

  //Menu
  $router->post('user-menu', 'UserController@userMenus');

  //Reporting
  $router->post('report', 'ReportController@getReport');
  $router->post('report-press1-campaign', 'Press1CampaignReportController@getReport');

  $router->post('all-dtmf', 'Press1CampaignReportController@allDtmf');


  $router->post('report-lead-id', 'ReportController@getReportByLeadId');
  $router->post('daily-call-report', 'ReportController@dailyCallReport');

  // ─── Fast Dashboard (pre-aggregated snapshots, < 100ms) ──────────────────
  $router->get('dashboard/fast-stats',           'DashboardController@getFastStats');
  $router->post('dashboard/trigger-aggregation', 'DashboardController@triggerAggregation');
  $router->get('daily-call-report/{logId}', 'ReportController@getDailyCallReportView');
  $router->get('daily-call-report', 'ReportController@getDailyCallReportLogs');

  $router->post('live-call', 'ReportController@getLiveCall');
  $router->get('live-calls', 'ReportController@getLiveCall');

  // ─── Real-time Call Monitoring (listen / whisper / barge) ────────────────
  $router->post('monitoring/listen',   'CallMonitoringController@listen');
  $router->post('monitoring/whisper',  'CallMonitoringController@whisper');
  $router->post('monitoring/barge',    'CallMonitoringController@barge');
  $router->post('monitoring/stop',     'CallMonitoringController@stop');
  $router->get('monitoring/active',    'CallMonitoringController@activeMonitors');

  // ─── Telecom Failover Management ─────────────────────────────────────────
  $router->get('telecom/failover/status',       'TelecomFailoverController@status');
  $router->post('telecom/failover/switch',      'TelecomFailoverController@switchProvider');
  $router->post('telecom/failover/reset-stats', 'TelecomFailoverController@resetStats');
  $router->post('export-report', 'ReportController@exportReport');

  // --- Advanced Reports ---
  $router->post('reports/campaign-performance', 'AdvancedReportController@campaignPerformance');
  $router->post('reports/agent-productivity',   'AdvancedReportController@agentProductivity');
  $router->post('reports/hourly-volume',        'AdvancedReportController@hourlyVolume');
  $router->post('reports/export',               'AdvancedReportController@export');
  $router->post('reports/daily',                'AdvancedReportController@dailyReport');
  $router->post('reports/disposition',          'AdvancedReportController@dispositionReport');
  // Alias used by AgentSummary frontend page
  $router->post('agent-report',                 'AdvancedReportController@agentProductivity');
  $router->post('transfer-report', 'ReportController@getTransferReport');
  $router->get('get-timezone-list', 'ReportController@getTimeZoneList');

  #call matrix ananlyzer
  $router->post('/call-matrix-report', 'CallMatrixReportController@store');
  $router->post('/call-matrix/process', 'CallMatrixReportController@process');
  $router->get('/call-matrix-report/{reference_id}', 'CallMatrixReportController@view');
  #$router->post('/call-matrix/{id}', 'CallMatrixReportController@processAndStoreCallAnalysis');

  Route::get('/call-analysis/{id}', [CallAnalysisController::class, 'show']);

  //login history

  $router->post('login-history', 'ReportController@loginHistory');

  //Campaign
  $router->post('campaign', 'CampaignController@getCampaign');
  $router->get('campaign-type', 'CampaignController@CampaignTypeList');

  $router->get('campaigns', 'CampaignController@CampaignList');
  $router->post('add-campaign', 'CampaignController@addCampaign');
  $router->post('edit-campaign', 'CampaignController@updateCampaign');
  $router->post('campaign-list', 'CampaignController@getCampaignAndList');
  $router->post('list-disposition', 'CampaignController@getDispositionAndList');
  $router->post('delete-list-disposition', 'CampaignController@deleteDispositionAndList');
  $router->post('copy-campaign', 'CampaignController@copyCampaign');
  $router->post('campaign-by-id', 'CampaignController@campaignById');
  $router->post('delete-campaign', 'CampaignController@deleteCampaign');
  $router->post('status-update-campaign', 'CampaignController@updateCampaignStatus');
  $router->post('status-update-hopper', 'CampaignController@updateCampaignHopper');

  // ─── Predictive Dialer Pacing ─────────────────────────────────────────────
  $router->get('dialer/pacing/{campaignId}',                 'DialerPacingController@snapshot');
  $router->post('dialer/pacing/{campaignId}/agent-state',    'DialerPacingController@updateAgentState');
  $router->post('dialer/pacing/{campaignId}/heartbeat',      'DialerPacingController@heartbeat');
  $router->post('dialer/pacing/{campaignId}/record-outcome', 'DialerPacingController@recordOutcome');
  $router->post('dialer/pacing/{campaignId}/reset',          'DialerPacingController@reset');

//campaign assign list

  $router->post('/campaign/assign-lists', 'CampaignController@assignLists');

  // ─── Lead Management (Deduplication + Assignment) ─────────────────────────
  $router->post('leads/check-duplicate',   'LeadManagementController@checkDuplicate');
  $router->post('leads/scan-duplicates',   'LeadManagementController@scanDuplicates');
  $router->post('leads/assign',            'LeadManagementController@assignLeads');
  $router->post('leads/auto-distribute',   'LeadManagementController@autoDistribute');
  $router->post('leads/dedup-batch',       'LeadManagementController@dedupBatch');




   //call timing schedule
  $router->post('/campaign/{id}/schedule', 'CampaignController@getCallSchedule');

  $router->group(['prefix' => 'call-timers'], function () use ($router) {
    $router->get('/', 'CallTimerController@index');
    $router->get('/{id}', 'CallTimerController@show');
    $router->post('/', 'CallTimerController@store');
    $router->post('/{id}', 'CallTimerController@update');
    $router->delete('/{id}', 'CallTimerController@destroy');
});




  //show history
  $router->post('show-upload-history', 'ShowHistoryController@HistoryList');

  //campaign type
  $router->get('campaign-type', 'CampaignTypeController@index');
  $router->put('campaign-type', 'CampaignTypeController@create');
  $router->get('campaign-type/{id}', 'CampaignTypeController@show');
  $router->post('campaign-type/{id}', 'CampaignTypeController@update');
  $router->get('delete-campaign-type/{id}', 'CampaignTypeController@delete');
  //crm list
  $router->post('crm-list', 'CrmListsController@crmList');
  $router->put('crm-list', 'CrmListsController@create');
  $router->get('crm-list/{id}', 'CrmListsController@show');
  $router->post('crm-list/{id}', 'CrmListsController@update');
  $router->delete('delete-crm-list/{id}', 'CrmListsController@delete');
  //sip_channel_provider
  $router->post('sip-channel-provider', 'SipChannelController@index');
  $router->put('sip-channel-provider', 'SipChannelController@create');
  $router->get('sip-channel-provider/{id}', 'SipChannelController@show');
  $router->post('sip-channel-provider/{id}', 'SipChannelController@update');
  $router->delete('delete-sip-channel-provider/{id}', 'SipChannelController@delete');

  //dtmf

  $router->get('dtmf-list', 'Press1CampaignReportController@dtmfList');


  //Disposition
  $router->post('disposition', 'DispositionController@getDisposition');
  $router->post('add-disposition', 'DispositionController@addDisposition');
  $router->post('edit-disposition', 'DispositionController@updateDisposition');
  $router->post('status-update-disposition', 'DispositionController@updateDispositionStatus');

  //Campaign Disposition
  $router->post('campaign-disposition', 'DispositionController@getCampaignDisposition');
  $router->post('edit-campaign-disposition', 'DispositionController@editCampaignDisposition');

  //Extension
  $router->get('/extension/{id}', 'ExtensionController@show');
  $router->get('/extension', 'ExtensionController@list');
  $router->post('extension', 'ExtensionController@getExtension');
  $router->post('extension-list', 'ExtensionController@getExtensionList');
  $router->get('/role', 'ExtensionController@roles');

  // $router->post('extension-list-crm', 'ExtensionController@getExtensionListCRM');


  $router->post('check-extension-live', 'ExtensionController@getExtensionLive');


  $router->post('add-extension', 'ExtensionController@addExtension');
  $router->post('edit-extension', 'ExtensionController@editExtension');
  $router->post('edit-extension-save', 'ExtensionController@editExtensionSave');
  $router->post('update-agent-password-by-admin', 'UserController@updateAgentByAdminPassword');
  $router->post('update-allowed-ip', 'UserController@updateAllowedIp');
  $router->post('hangup-conferences', 'UserController@hangupConferences');

  $router->post('check-extension', 'ExtensionController@checkExtension');
  $router->post('check-alt-extension', 'ExtensionController@checkAltExtension');
  $router->post('update-email', 'ExtensionController@updateEmail');


  $router->post('client_ip_list', 'ExtensionController@clientIpList');
  $router->post('new-extension-save', 'ExtensionController@saveNewExtension');
  $router->post('get-client-extension', 'ExtensionController@getClientExtensions');


  //sms templete
  $router->get('sms-templete', 'SmsTempleteController@index');
  $router->post('sms-templete', 'SmsTempleteController@getSmsTemplete');
  $router->post('add-sms-templete', 'SmsTempleteController@addSmsTemplete');
  $router->post('edit-sms-templete', 'SmsTempleteController@editSmsTemplete');
  $router->post('delete-sms-templete', 'SmsTempleteController@deleteSmsTemplete');
  $router->post('get-sms-email-list', 'SmsTempleteController@getEmailSmsList');
  $router->post('sms-preview', 'SmsTempleteController@getSmsPreview');
  $router->post('sms-preview-crm', 'SmsTempleteController@getSmsPreviewCRM');
  $router->post('update-sms-templete-status', 'SmsTempleteController@updateStatus');

  $router->delete('sms-template/{id}', 'SmsTempleteController@delete');


  //voice templete
  $router->get('voice-templete', 'VoiceTempleteController@index');
  $router->post('voice-templete', 'VoiceTempleteController@getVoiceTemplete');
  $router->post('add-voice-templete', 'VoiceTempleteController@addVoiceTemplete');
  $router->post('edit-voice-templete', 'VoiceTempleteController@editVoiceTemplete');
  $router->post('delete-voice-templete', 'VoiceTempleteController@deleteVoiceTemplete');
  $router->post('get-voice-email-list', 'VoiceTempleteController@getEmailVoiceList');
  $router->post('voice-preview', 'VoiceTempleteController@getVoicePreview');
  $router->delete('voice-template/{id}', 'VoiceTempleteController@delete');



  //DNC
  $router->post('dnc', 'DncController@getDnc');
  $router->post('ivr-logs', 'Press1CampaignReportController@getIvrLogs');

  $router->post('edit-dnc', 'DncController@editDnc');
  $router->post('delete-dnc', 'DncController@deleteDnc');
  $router->post('add-dnc', 'DncController@addDnc');
  $router->post('upload-dnc', 'DncController@uploadDnc');
  $router->get('/dnc/fetch_data', 'DncController@fetch_data');


  //done api postman
  //Exclude Number
  $router->post('exclude-number', 'ExcludeNumberController@getExcludeNumber');
  $router->post('edit-exclude-number', 'ExcludeNumberController@editExcludeNumber');
  $router->post('delete-exclude-number', 'ExcludeNumberController@excludeNumberDelete');
  $router->post('add-exclude-number', 'ExcludeNumberController@addExcludeNumber');

  $router->post('upload-exclude-number', 'ExcludeNumberController@uploadExcludeNumber');

  //Label
  $router->post('label', 'LabelController@getLabel');  //done
  $router->post('edit-label', 'LabelController@editLabel'); //done
  $router->post('add-label', 'LabelController@addLabel'); //done
  $router->post('status-update-label', 'LabelController@updateLabelStatus'); //done
  $router->post('/label/updateDisplayOrder', 'LabelController@updateDisplayOrder');//pending



  //Api
  $router->post('api-data', 'ApiController@getApi');
  $router->post('edit-api', 'ApiController@editApi');
  $router->post('delete-api', 'ApiController@deleteApi');
  $router->post('add-api', 'ApiController@addApi');
  $router->post('copy-api', 'ApiController@copyApi');

  //Recycle Rule
  $router->post('recycle-rule', 'RecycleController@getRecycleRule');
  $router->post('edit-recycle-rule', 'RecycleController@editRecycleRule');
  $router->post('add-recycle-rule', 'RecycleController@addRecycleRule');
  $router->post('delete-leads-rule', 'RecycleController@deleteLeadRule');
  $router->post('search-recycle-rule', 'RecycleController@searchRecycleRule');


  //List
  $router->get('count-lists', 'ListsController@countList');
  $router->get('list-data/{id}/content', 'ListsController@getListContentView'); //done
  $router->post('list-data/{id}/content', 'ListsController@getListContentView'); //done
  $router->post('list', 'ListsController@getList'); //done
  $router->post('raw-list', 'ListsController@getListWithoutCampaign'); //done

  $router->post('edit-list', 'ListsController@editList');  //done for delete not edit
  $router->post('add-list', 'ListsController@addList');
  $router->post('add-list-api',  'ListsController@addListUsingApi');//done
  $router->post('parse-list-headers',       'ListsController@parseListHeaders');
  $router->post('import-list-with-mapping', 'ListsController@importListWithMapping');
  $router->post('get-list-mapping',         'ListsController@getListMapping');
  $router->post('update-list-mapping',      'ListsController@updateListMapping');
  $router->post('search-leads', 'ListsController@searchLeads');
  $router->post('list-header', 'ListsController@getListHeader');
  $router->post('status-update-list', 'ListsController@updateListStatus'); //done
  $router->post('status-update-campaign-list', 'ListsController@updateCampaignListStatus');
  $router->post('get-data-for-edit-lead-page', 'ListsController@getDataForEditLeadPage');
  $router->post('get-data-for-edit-lead-page_copy', 'ListsController@getDataForEditLeadPage_copy');

  $router->post('update-lead-data', 'ListsController@updateLeadData');
  $router->post('update-lead-data_copy', 'ListsController@updateLeadData_copy');

  $router->post('change-disposition', 'ListsController@changeDisposition');

  $router->get('list/{id}/content', 'ListsController@getListContent');


  //Dialer
  $router->post('click2call', 'DialerController@outboundAIDial');

  $router->post('agent-campaign', 'DialerController@getAgentCampaign');
  $router->post('lead-temp', 'DialerController@getLeadCountInTemp');
  $router->post('extension-login', 'DialerController@extensionLogin');
  $router->post('call-number', 'DialerController@callNumber');
  $router->post('hang-up', 'DialerController@hangUp');
  $router->post('dtmf', 'DialerController@dtmf');
  $router->post('user-logout', 'DialerController@logout');
  $router->post('extension-logout', 'DialerController@extensionlogout');
  $router->post('disposition-campaign', 'DialerController@dispositionCampaign');
  $router->post('disposition_by_campaignId', 'DialerController@dispositionByCampaignId');
  $router->post('get-lead', 'DialerController@getLead');
  $router->get('get-lead', 'DialerController@getLead');
  $router->post('save-disposition', 'DialerController@saveDisposition');
  $router->post('redial-call', 'DialerController@redialCall');

  $router->post('voicemail-drop', 'DialerController@voicemailDrop');
  $router->post('send-to-crm', 'DialerController@sendToCrm');
  $router->post('send-to-crm-post', 'DialerController@sendToCrmPost');

  $router->post('update-lead/{leadId}', 'DialerController@updateLeadData');
  $router->post('view-notes/{leadId}', 'DialerController@showNotesData');

  $router->post('listen-call', 'DialerController@listenCall');
  $router->post('barge-call', 'DialerController@bargeCall');

  // Frontend API aliases (added for frontend compatibility)
  $router->post('extension-logout', 'DialerController@logout');
  $router->post('send-dtmf', 'DialerController@dtmf');
  $router->post('disposition-by-campaign-id', 'DialerController@dispositionByCampaignId');
  $router->post('add-new-lead-pd', 'DialerController@addNewLeadPd');
  $router->post('webphone/switch-access', 'DialerController@switchWebPhoneUse');
  $router->get('webphone/status', 'DialerController@webPhoneStatus');

  //sms Number
  $router->post('sms', 'SmsController@getSms');
  $router->get('sms', 'SmsController@getSms');
  $router->get('sms-by-did', 'SmsController@getSmsByDid');
  $router->post('sms-by-did', 'SmsController@getSmsByDid');
  $router->get('sms-by-did-recent', 'SmsController@getSmsByDidRecent');
  $router->post('sms-by-did-recent', 'SmsController@getSmsByDidRecent');
  $router->get('sms_did_list', 'SmsController@smsDidList');
  $router->get('sms-did-list-crm', 'SmsController@smsDidListCRM');


  $router->post('send-sms', 'SmsController@sendSms');

  $router->post('sms-count', 'ReportController@getSmsCount');
  $router->get('unread-sms-count', 'SmsController@getUnreadSms');
  $router->post('unread-sms-count', 'SmsController@getUnreadSms');
  $router->post('unread-sms-count-openai', 'SmsController@getUnreadSmsOpenAI');
  $router->post('sms/mark-read', 'SmsController@markRead');

  #sms ai
  $router->post('add-open-ai-setting', 'OpenAiController@create');
  $router->get('open-ai-setting', 'OpenAiController@list');
  $router->post('update-open-ai-setting/{id}', 'OpenAiController@update');
  $router->post('sms-ai-history', 'OpenAiController@smsHistory');
  $router->post('delete-message-ai', 'OpenAiController@delete');


  #chat ai
  $router->post('chat-ai-history', 'ChatAiController@chatHistory');
  $router->post('send-text-to-ai', 'ChatAiController@create');
  $router->get('chat-ai-setting', 'ChatAiController@list');
  $router->post('update-chat-ai-setting/{id}', 'ChatAiController@update');



  $router->post('add-chat-ai-setting', 'ChatAiController@createSetting');






  $router->post('unread-mailbox', 'MailboxController@getUnreadMailBox');

  //fax Number
  $router->post('fax', 'FaxController@getFax');
  $router->post('fax/{id}', 'FaxController@getFaxPdf');
  $router->post('send-fax', 'FaxController@sendFax');
  $router->post('delete-fax', 'FaxController@deleteFax');
  $router->post('receive-fax-list', 'FaxController@receiveFaxList');
  $router->post('get-unread-fax-count', 'FaxController@getUnreadFaxCount');


  //DID
  $router->get('did', 'DidsController@index');
  $router->post('did', 'DidsController@getList');
  $router->post('list-by-email', 'DidsController@getListByEmailId');
  $router->post('edit-did', 'DidsController@editDid');
  $router->post('add-did', 'DidsController@addDid');
  $router->post('did_detail', 'DidsController@did_detail');
  $router->post('save-edit-did', 'DidsController@saveEdit');
  $router->post('delete-did', 'DidsController@deleteDid');
  $router->post('get-call-timings', 'DidsController@getCallTimings');
  $router->post('get-department-call-timings', 'DidsController@getDepartmentCallTimings');
  $router->post('save-call-timings', 'DidsController@saveCallTimings');
  $router->post('get-all-holidays', 'DidsController@getAllHolidays');
  $router->post('get-holiday-datail', 'DidsController@getHolidayDetail');
  $router->post('save-holiday-detail', 'DidsController@saveHolidayDetail');
  $router->post('delete-holiday', 'DidsController@deleteHoliday');
  $router->post('get-department-list', 'DidsController@getDepartmentList');
  $router->post('get-did-list-from-sale', 'DidsController@getDidListFromSale');
  $router->post('buy-save-selected-did', 'DidsController@buySaveDid');
  $router->post('buy-save-selected-did-plivo', 'DidsController@buySaveDidPlivo');
  $router->post('buy-save-selected-did-telnyx', 'DidsController@buySaveDidTelnyx');
  $router->post('buy-save-selected-did-twilio', 'DidsController@buySaveDidTwilio');
  $router->post('get-did-by-id', 'DidsController@getDetailById');


  $router->post('upload-did', 'DidsController@uploadDid');

  //plivo
  $router->post('get-did-list-from-plivo', 'DidsController@getDidListFromPlivo');
  //telnyx
  $router->post('get-did-list-from-telnyx', 'DidsController@getDidListFromTelnyx');
  $router->post('get-did-list-from-twilio', 'DidsController@getDidListFromTwilio');
  $router->post('get-did-list-for-areacode', 'BuyNoAreaCodeController@getDidListForAreaCode');
  $router->post('buy-save-selected-did-areacode', 'BuyNoAreaCodeController@buySaveDidAreacode');




  //callback

  $router->post('callback', 'CallBackController@getCallBack');
  $router->post('callback/edit', 'CallBackController@editCallback');
  $router->get('callback-reminder/stop', "CallBackController@stopReminder");
  $router->get('callback-reminder/show', "CallBackController@showReminder");
  $router->get('callback-reminder/status', "CallBackController@getReminderStatus");

  //ivr
  $router->post('ivr', 'IvrController@getIvr');
  $router->post('add-ivr', 'IvrController@addIvr');
  $router->post('add-voice-mail-drop', 'UserController@addVoiceMailDrop');
  $router->post('add-voice-ai', 'UserController@addVoiceAi');

  $router->get('view-voicemail', 'UserController@viewVoiceMailDrop');
  $router->get('view-voice-ai', 'UserController@viewVoiceAi');

  $router->post('edit-voicemail', 'UserController@editVoiceMailDrop');
  $router->post('update-voiemail', 'UserController@updateVoicemailDrop');

  $router->post('edit-voiceai', 'UserController@editVoiceAi');
  $router->post('update-voiceai', 'UserController@updateVoiceAi');
  $router->post('delete-voiceai', 'UserController@deleteVoiceAi');



  $router->post('update-voice-mail', 'UserController@updateVoiceMail');


  $router->post('edit-ivr', 'IvrController@editIvr');
  $router->post('delete-ivr', 'IvrController@deleteIvr');

  //ringless voicemail ivr

  $router->post('ringless-ivr', 'Ringless\RinglessIvrController@getIvr');
  $router->post('add-ringless-ivr', 'Ringless\RinglessIvrController@addIvr');

  $router->post('edit-ringless-ivr', 'Ringless\RinglessIvrController@editIvr');
  $router->post('delete-ringless-ivr', 'Ringless\RinglessIvrController@deleteIvr');

  //sip gateway

  $router->get('sip-gateways', 'SipGateways\SipGatewaysController@sipGatwayList');
  $router->put('sip-gateway', 'SipGateways\SipGatewaysController@create');
  $router->get('sip-gateways/{id}', 'SipGateways\SipGatewaysController@edit');
  $router->post('update-sip-gateways', 'SipGateways\SipGatewaysController@update');
  $router->get('sip-gateway-delete/{id}', 'SipGateways\SipGatewaysController@delete');








  //audio message

  $router->get('audio-message', 'AudioMessageController@list');
  $router->post('add-audio-message', 'AudioMessageController@addAudioMessage');
  $router->post('edit-audio-message', 'AudioMessageController@ediAudioMessage');






  //ivr menu

  $router->post('ivr-menu', 'IvrMenuController@getIvrMenu');
  $router->post('delete-ivr-menu', 'IvrMenuController@deleteIvrMenu');
  $router->post('edit-ivr-menu', 'IvrMenuController@editIvrMenu');

  $router->post('add-ivr-menu', 'IvrMenuController@addIvrMenu');


  //dest type list

  $router->post('dest-type', 'DestTypeController@getDestType');


  //  ring group

  $router->post('ring-group', 'RingGroupController@getRingGroup');
  $router->post('edit-dnc', 'DncController@editDnc');
  $router->post('delete-ring-group', 'RingGroupController@deleteRingGroup');
  $router->post('add-ring-group', 'RingGroupController@addRingGroup');
  $router->post('edit-ring-group', 'RingGroupController@editRingGroup');
  $router->post('upload-dnc', 'DncController@uploadDnc');

  $router->get('extension-ring-group', 'RingGroupController@mapExtensionRingGroup');


  //mailbox
  $router->get('mailbox', 'MailboxController@getMailbox');
  $router->post('mailbox', 'MailboxController@getMailbox');
  $router->post('edit-mailbox', 'MailboxController@editMailBox');

  $router->post('delete-mailbox', 'MailboxController@deleteMailbox');


  //Dashboard

  $router->post('dashboard', 'DashboardController@index');
  $router->post('dashboard/revenue-metrics', 'DashboardController@getRevenueMetrics');
  $router->post('dashboard-state', 'DashboardController@setDashboardState');
  $router->get('dashboard-state', 'DashboardController@getDashboardState');
  $router->get('count-dids', 'DidsController@countDids');
  $router->post('did-count', 'DidsController@getListCount');
  $router->post('user-count', 'ExtensionController@getExtensionCount');
  $router->post('campaigns-count', 'CampaignController@getCampaignCount');
  $router->post('lead-count', 'ListsController@getLeadCount');
  $router->post('cdr-call-count', 'ReportController@cdrCallCount');
  $router->post('cdr-call-agent-count', 'ReportController@cdrCallAgentCount');
  $router->post('cdr-count-range', 'ReportController@cdrCallsByRange');
  $router->post('disposition-wise-call', 'ReportController@getDispositionSummary');
  $router->post('state-wise-call', 'ReportController@getStateWiseSummary');
  $router->post('cdr-dashboard-summary', 'ReportController@getCdrDashboardSummary');

  $router->post('voicemail-count', 'ReportController@getVoicemailCount');
  $router->post('voicemail-unread', 'ReportController@getVoicemailUnread');
  $router->post('extension-summary', 'ReportController@cdrExtensionSummary');
  $router->post('employee-directory', 'DidsController@getEmployeeDirectory');
  $router->post('inbound-count-avg', 'DidsController@getInboundCountAvg');
  $router->post('upload-did', 'DidsController@uploadDid');

  $router->get('wallet/balance', 'WalletController@getWalletBalance');
  $router->get('wallet/transactions', 'WalletController@getWalletTransactions');


  //smtp setting
  //DNC
  $router->post('smtp', 'SmtpController@getSmtp');
  $router->post('edit-smtp', 'SmtpController@editSmtp');
  $router->post('delete-smtp', 'SmtpController@deleteSmtp');
  $router->post('add-smtp', 'SmtpController@addSmtp');
  $router->post('smtp-by-user-id', 'SmtpController@smtpByUserId');
  $router->post('copy-smtp', 'SmtpController@copySmtp');
  $router->post('status-update-smtp', 'SmtpController@updateSmtpStatus');



  //conferencing
  $router->post('conferencing', 'ConferencingController@getConferencing');
  $router->post('add-conferencing', 'ConferencingController@addConferencing');
  $router->post('edit-conferencing', 'ConferencingController@editConferencing');
  $router->post('delete-conferencing', 'ConferencingController@deleteConferencing');


  //marketing campaign
  $router->get('marketing-campaigns', 'MarketingCampaignController@index');
  $router->put('marketing-campaign', 'MarketingCampaignController@create');
  $router->get('marketing-campaign/{id}', 'MarketingCampaignController@show');
  $router->post('marketing-campaign/{id}', 'MarketingCampaignController@update');
  $router->post('status-update-marketing', 'MarketingCampaignController@updateGroupStatus');

  //marketing campaign schedule
  $router->get('marketing-campaigns-schedule/{id}', 'MarketingCampaignScheduleController@index');
  $router->put('marketing-campaign-schedule', 'MarketingCampaignScheduleController@create');
  $router->put('marketing-campaign-schedule-sms', 'MarketingCampaignScheduleController@createSMS');

  $router->get('marketing-campaign-schedule/{id}/logs', 'MarketingCampaignScheduleController@getLogs');
  $router->get('marketing-campaign-schedule/{id}', 'MarketingCampaignScheduleController@show');
  $router->post('marketing-campaign-schedule/{id}', 'MarketingCampaignScheduleController@update');
  $router->post('delete-schedule', 'MarketingCampaignScheduleController@deleteSchedule');
  $router->post('abort-schedule', 'MarketingCampaignScheduleController@abortSchedule');

  $router->patch('marketing-campaign-schedule/resume/{id}', 'MarketingCampaignScheduleController@resumeProcessing');
  $router->post('marketing-campaign-schedule-run/{id}/retry', 'MarketingCampaignScheduleController@retryRun');

  $router->post('find-listheader', 'MarketingCampaignScheduleController@findListHeader');

  //country
  $router->post('country-list', 'CountryController@getCountry');
  //state
  $router->post('state-list', 'CountryController@getState');
  $router->post('phone-country-list', 'CountryController@getPhoneCountry');



  //Label
  $router->post('extension_live', 'LabelController@gextensionLive');
  $router->post('delete-ext-live', 'LabelController@deleteExt');

  $router->get("servers/asterisk-server", "ServerController@asteriskServers");
  $router->get("servers/client-servers", "ServerController@clientServers");

  $router->get('sender-data/{id}', 'EmailTempleteController@senderData');
  $router->get('email-template/{id}/{list_id}/{lead_id}', 'EmailTempleteController@show');
  $router->post('email-template-crm', 'EmailTempleteController@showCRM');

  $router->get('label-data/{id}/{list_id}/{lead_id}', 'EmailTempleteController@labelValue');
  $router->post('sender-data', 'EmailTempleteController@SenderValue');

  #Email
  $router->post('send-email/generic', 'MailController@sendGenericEmail');
  $router->post('send-email/generic-attachment', 'MailController@sendEmailGenericAttachment');



  $router->post('send-email/generics', 'MailController@sendGenericEmailCRM');
  $router->post('send-email/test-low-lead', 'MailController@testLowLead');

  #Notifications
  $router->get("notifications", "NotificationController@index");
  $router->post("notifications", "NotificationController@saveSubscriptions");
  $router->post("device/token", "NotificationController@saveDeviceToken");
  $router->post("notifications/send-direct", "NotificationController@sendDirectNotification");

  #CDR
  $router->post("active-extension-group-list", "ReportController@getActiveExtensionByGroup");
  $router->post("extension-group-list", "ReportController@getExtensionByGroup");
  $router->post("get-cdr", "ReportController@getCDR");
  $router->post("get-cdr_copy", "ReportController@getCDR_copy");


  #ipWhiteListLoggedInUser
  $router->post("check-loggedin-user", "UserController@ipWhiteListLoggedInUser");
  $router->post("user-token-data", "UserController@userTokenData");
  $router->post("delete-usertoken", "UserController@deleteUserToken");

  $router->post("get-extension-by-parentid", "UserController@getextensionByParentId");



  $router->post('check-email', 'ExtensionController@checkEmail');

  //api list url
  $router->post("lead-source-configs", "LeadSourceConfigController@index");
  $router->put('lead-source-config', 'LeadSourceConfigController@create');
  $router->get('header-by-listid/{id}', 'LeadSourceConfigController@headerByListId');
  $router->get('delete-lead-source-config/{id}', 'LeadSourceConfigController@delete');
  $router->post("lead-data", "LeadSourceConfigController@leadData");
  $router->get('insert-lead-source', 'LeadSourceConfigController@insertLeadSource');
  //did
  $router->post('check-default-did', 'DidsController@checkDefaultDid');

  // Show user packages
  $router->get('user-packages', 'UserPackagesController@getUsersPackages');
  $router->get('user-package/{userId}', 'UserPackagesController@getUserPackageDetails');
  $router->get('user-package-urls/{userId}', 'UserPackagesController@getUserPackageDetailsUrls');

  $router->post('user-package/update/{packageKey}', 'UserPackagesController@updateUserPackage');
  $router->post('user-package/delete/{packageKey}', 'UserPackagesController@deleteUserPackage');
  $router->get('client-packages', 'UserPackagesController@getClientPackages');
  $router->get('client-packages/trial', 'UserPackagesController@getTrialPackageDetails');

  //cart
  $router->get('cart', 'CartController@getCartItems');
  $router->get('cart-new', 'CartController@getCartItemsNew');
  $router->post('cart/add/{packageName}', 'CartController@addToCart');
  $router->post('cart/update/{cartId}', 'CartController@updateCart');
  $router->post('cart/delete/{cartId}', 'CartController@deleteCart');
  $router->get('cart/count', 'CartController@getCartCount');
  $router->get('cart/total', 'CartController@getCartTotalAmount');

  //orders
  $router->get('orders', 'OrdersController@index');
  $router->get('order/{orderId}', 'OrdersController@show');

  //google languages
  $router->post('get-google-languages', "GoogleLanguageController@getlanguages");
  $router->post('get-voice-name-on-google-languages', "GoogleLanguageController@getVoiceNameOnLanugage");

  //Coupons
  $router->get('coupons-list', "CouponController@getCouponsList");
  $router->post('coupon-detail', "CouponController@getCouponDetail");
  $router->post('coupon-edit', "CouponController@edit");

  $router->group(['middleware' => 'hasComponent:match-uri',], function () use ($router) {
    $router->get('live-conference', 'LiveConferencingController@index');
    $router->get('recording-conference', 'ConferenceRecordingController@index');
  });

  $router->group(['middleware' => 'hasComponent:receive-fax',], function () use ($router) {
    $router->post("fax-did", "DidsController@faxDidList");
    $router->post("fax-did-user", "DidsController@faxDidUserList");
  });


  //stripe payment apis
  $router->get('stripe/get-customer-id', "StripeController@getStripeCustomerId");
  $router->get('stripe/get-customer-payment-method', "StripeController@getStripeCustomerPaymentMethod");
  $router->post('stripe/create-customer-payment-method', "StripeController@createStripeCustomerPaymentMethod");
  $router->post('stripe/attach-customer-and-payment-method', 'StripeController@attachCustomerAndPaymentMethod');
  $router->post('stripe/charge', 'StripeController@charge');
  $router->post('stripe/get-payment-method', 'StripeController@getPaymentMethod');
  $router->post('stripe/save-card', 'StripeController@saveCard');
  $router->post('stripe/update-card', 'StripeController@updateCard');
  $router->post('stripe/save-card-new', 'StripeController@saveCardNew');

  //Subscription checkout/Add balance
  $router->post('checkout', "CheckoutController@processCheckout");

  //opening questions
  $router->get('opening-questions', "OpeningQuestionsController@getNextQuestion");

  //delete payment method
  $router->post('stripe/delete-stripe-payment_method', 'StripeController@deletePaymentMethod');

  $router->get('opening-questions', "OpeningQuestionsController@getQuestionsInfo");
  $router->get('opening-questions/next', "OpeningQuestionsController@getNextQuestion");
  $router->get('opening-questions/hide/permanently', "OpeningQuestionsController@hideQuestionsPermanently");
  $router->get('opening-questions/show/permanently', "OpeningQuestionsController@showQuestionsPermanently");
  $router->get('opening-questions/status', "OpeningQuestionsController@getStatus");

  $router->post('call-lead', "CallLeadController@callLead");

  //Chat application
  $router->post('/idInfo', 'MessagesController@idFetchData');
  $router->post('/sendMessage', 'MessagesController@send');
  $router->post('/fetchMessages', 'MessagesController@fetch');
  $router->get('/getContacts', 'MessagesController@getContacts');
  $router->get('/search', 'MessagesController@search');
  $router->post('/favorites', 'MessagesController@getFavorites');
  $router->post('/shared', 'MessagesController@sharedPhotos');
  $router->post('/makeSeen', 'MessagesController@seen');
  $router->post('/updateContacts', 'MessagesController@updateContactItem');
  $router->post('/setActiveStatus', 'MessagesController@setActiveStatus');
  $router->post('/updateSettings', 'MessagesController@updateSettings');
  $router->post('/star', 'MessagesController@markFavorite');
  $router->post('/deleteConversation', 'MessagesController@deleteConversation');
  //TODO: not worked on this API
  $router->get('/download/{fileName}', 'MessagesController@download');

  //TODO: this API is to verify the meeting code, currently not in use, if won't be used in future then remove it.
  $router->post('/meeting/verify', 'MeetingsController@verify');

  //return company users for chat application
  $router->get('company-users', 'ContactsController@getCompanyUsers');

  // Team Chat Routes
  $router->get('team-chat/widget-data', 'TeamChatController@getWidgetData');
  $router->get('team-chat/conversations', 'TeamChatController@getConversations');
  $router->post('team-chat/conversations', 'TeamChatController@createConversation');
  $router->post('team-chat/conversations/direct', 'TeamChatController@getOrCreateDirectConversation');
  $router->get('team-chat/conversations/{uuid}', 'TeamChatController@getConversation');
  $router->get('team-chat/conversations/{uuid}/messages', 'TeamChatController@getMessages');
  $router->post('team-chat/conversations/{uuid}/messages', 'TeamChatController@sendMessage');
  $router->post('team-chat/conversations/{uuid}/attachments', 'TeamChatController@uploadAttachment');
  $router->post('team-chat/conversations/{uuid}/read', 'TeamChatController@markAsRead');
  $router->post('team-chat/conversations/{uuid}/typing', 'TeamChatController@typing');
  $router->post('team-chat/conversations/{uuid}/participants', 'TeamChatController@addParticipants');
  $router->post('team-chat/conversations/{uuid}/leave', 'TeamChatController@leaveConversation');
  $router->get('team-chat/attachments/{attachmentId}/download', 'TeamChatController@downloadAttachment');
  $router->post('team-chat/presence', 'TeamChatController@updatePresence');
  $router->get('team-chat/users/online', 'TeamChatController@getOnlineUsers');
  $router->get('team-chat/users/search', 'TeamChatController@searchUsers');
  $router->get('team-chat/unread-count', 'TeamChatController@getUnreadCount');
  $router->post('team-chat/pusher/auth', 'TeamChatController@pusherAuth');
  $router->get('team-chat/ice-servers', 'TeamChatController@getIceServers');
  $router->post('team-chat/conversations/{uuid}/call', 'TeamChatController@initiateCall');
  $router->post('team-chat/conversations/{uuid}/call/signal', 'TeamChatController@callSignal');
  $router->post('team-chat/conversations/{uuid}/call/accept', 'TeamChatController@acceptCall');
  $router->post('team-chat/conversations/{uuid}/call/end', 'TeamChatController@endCall');

  // Team Chat Widget Token Management (Protected)
  $router->get('team-chat/widget/tokens', 'TeamChatWidgetController@getTokens');
  $router->post('team-chat/widget/tokens', 'TeamChatWidgetController@createToken');
  $router->get('team-chat/widget/tokens/{tokenId}/embed-code', 'TeamChatWidgetController@getEmbedCode');
  $router->put('team-chat/widget/tokens/{tokenId}', 'TeamChatWidgetController@updateToken');
  $router->post('team-chat/widget/tokens/{tokenId}/toggle', 'TeamChatWidgetController@toggleToken');
  $router->delete('team-chat/widget/tokens/{tokenId}', 'TeamChatWidgetController@revokeToken');
  $router->get('team-chat/widget/users', 'TeamChatWidgetController@getAvailableUsers');

  // Tariff Label Fields
  $router->get('tariff-labels', 'TariffLabelController@index');
  $router->put('tariff-plan', 'TariffLabelController@create');
  $router->get('tariff-plan/{id}', 'TariffLabelController@show');
  $router->post('tariff-plan/{id}', 'TariffLabelController@update');
  $router->get('delete-tariff-label/{id}', 'TariffLabelController@delete');

  // Tariff Label Fields values
  $router->get('tariff-label-values', 'TariffLabelValuesController@index');
  $router->put('tariff-label-value', 'TariffLabelValuesController@create');
  $router->get('tariff-label-value/{id}', 'TariffLabelValuesController@show');
  $router->post('tariff-label-value/{id}', 'TariffLabelValuesController@update');
  $router->get('delete-tariff-label-value/{id}', 'TariffLabelValuesController@delete');

  // Allowed IPS Fields
  $router->get('allowed-ips', 'AllowedIpController@index');
  $router->put('allowed-ip', 'AllowedIpController@create');
  $router->get('allowed-ip/{id}', 'AllowedIpController@show');
  $router->post('allowed-ip/{id}', 'AllowedIpController@update');
  $router->get('delete-allowed-ip/{id}', 'AllowedIpController@delete');
  $router->post('status-update-allowed-ip', 'AllowedIpController@updateAllowedIpStatus');

  //voip configurations
  $router->get('voip-configurations', 'VoipConfigurationController@index');
  $router->put('voip-configuration', 'VoipConfigurationController@create');
  $router->get('voip-configuration/{id}', 'VoipConfigurationController@show');
  $router->post('voip-configuration/{id}', 'VoipConfigurationController@update');
  $router->get('delete-voip-configuration/{id}', 'VoipConfigurationController@delete');


  //Custom Field Label
  $router->get('custom-field-labels', 'CustomFieldLabelController@index');
  $router->put('custom-field-label', 'CustomFieldLabelController@create');
  $router->get('custom-field-label/{id}', 'CustomFieldLabelController@show');
  $router->post('custom-field-label/{id}', 'CustomFieldLabelController@update');
  $router->get('delete-custom-field-label/{id}', 'CustomFieldLabelController@delete');

  //custom field label values
  $router->get('custom-field-labels-values', 'CustomFieldLabelsValuesController@index');
  $router->put('custom-field-labels-value', 'CustomFieldLabelsValuesController@create');
  $router->get('custom-field-value/{id}', 'CustomFieldLabelsValuesController@show');
  $router->post('custom-field-value/{id}', 'CustomFieldLabelsValuesController@update');
  $router->get('custom-label-value/{id}', 'CustomFieldLabelsValuesController@showCustomLabelValue');
  $router->get('delete-custom-field-value/{id}', 'CustomFieldLabelsValuesController@delete');
  $router->get('area-code-list', 'AreaCodeController@index');



  //cli report

  $router->post('cli-report', 'CliReportController@index');
  $router->post('run-manually-call-for-cname', "CliReportController@callManually");
  $router->post('run-manually-call-for-did', "CliReportController@callManuallyDID");
  Route::get('/cli-report/fetch_data', 'CliReportController@fetch_data');


  $router->get('find-cli-report/{number}', "CliReportController@findCliReport");

  #for view the client details
  $router->get('client/{id}', 'ClientController@show');

  #hubspot

  $router->get('crm-lists', 'CrmListsController@crmLists');
  $router->get('hubspot-lists', 'HubspotController@lists');

  $router->get('get-contact-in-a-list/{id}', 'HubspotController@getContactInAList');





  $router->post('dialer-all-count-crm', 'DialerAllCountController@indexCrm');

  #call transfer

  $router->post('direct-call-transfer', 'DialerController@directCallTransfer');
  $router->post('warm-call-transfer', 'DialerController@warmCallTransfer');
  $router->post('warm-call-transfer-c2c-crm', 'DialerController@warmCallTransfer');
  $router->post('merge-call-with-transfer', 'DialerController@mergeCallWithTransfer');
  $router->post('merge-call-transfer', 'DialerController@mergeCallWithTransfer');
  $router->post('leave-call-transfer', 'DialerController@leaveConferenceTransfer');


  $router->post('check-line-details', 'DialerController@checkLineDetails');
  $router->post('check-extension-live-for-transfer', 'DialerController@checkExtensionLiveDetails');
  $router->post('leave-conference-transfer', 'DialerController@leaveConferenceTransfer');




  /* Ringless Voicemail*/

  $router->get('ringless/campaign', 'Ringless\RinglessCampaignController@index');
  $router->post('ringless/campaign/add', 'Ringless\RinglessCampaignController@storeCampaign');
  $router->get('ringless/campaign/edit', 'Ringless\RinglessCampaignController@showEditCampaign');
  $router->post('ringless/campaign/edit', 'Ringless\RinglessCampaignController@updateCampaign');
  $router->post('ringless/campaign/show', 'Ringless\RinglessCampaignController@campaignById');
  $router->post('ringless/campaign/delete', 'Ringless\RinglessCampaignController@deleteCampaign');
  $router->post('ringless/campaign/update-status', 'Ringless\RinglessCampaignController@updateCampaignStatus');
  $router->post('ringless/campaign/copy', 'Ringless\RinglessCampaignController@copyCampaign');
  $router->post('ringless/campaign-list', 'Ringless\RinglessCampaignController@getCampaignAndList');
  //list
  /*$router->post('ringless/list', 'Ringless\RinglessListController@index');

  $router->post('ringless/list/add', 'Ringless\RinglessListController@addList');
  $router->post('ringless/list/edit', 'Ringless\RinglessListController@editList');
  $router->post('ringless/list/updateStatus', 'Ringless\RinglessListController@updateListStatus');
  $router->get('ringless/list/{id}/content', 'Ringless\RinglessListController@getListContent');*/


  //sms ai lists

  $router->get('ringless/lists', 'Ringless\RinglessListController@index');
  $router->put('ringless/list/add', 'Ringless\RinglessListController@create');
  $router->get('ringless/list/view/{id}', 'Ringless\RinglessListController@show');
  $router->post('ringless/list/update/{id}', 'Ringless\RinglessListController@update');
  $router->post('ringless/list/update-status', 'Ringless\RinglessListController@updateStatus');
  $router->get('ringless/list/delete/{id}', 'Ringless\RinglessListController@delete');
  $router->get('ringless/list/recycle/{id}', 'Ringless\RinglessListController@recycle');

  //reports
  $router->get('ringless/reports/call-data', 'Ringless\RinglessCallReportController@getDefaultReport');
  $router->post('ringless/reports/call-data', 'Ringless\RinglessCallReportController@getReport');

  //billing
  $router->post('ringless/stripe/save-card', 'Ringless\RinglessPaymentMethodController@saveCard');
  $router->get('ringless/stripe/get-customer-payment-method', "Ringless\RinglessPaymentMethodController@getStripeCustomerPaymentMethod");
  $router->post('ringless/stripe/get-payment-method', 'Ringless\RinglessPaymentMethodController@getPaymentMethod');
  $router->post('ringless/stripe/update-card', 'Ringless\RinglessPaymentMethodController@updateCard');
  $router->post('ringless/stripe/delete-stripe-payment_method', 'Ringless\RinglessPaymentMethodController@deletePaymentMethod');
  $router->post('ringless/stripe/recharge', "Ringless\RinglessRechargeController@recharge");
  $router->get('ringless/wallet/transactions', 'Ringless\RinglessWalletController@getWalletTransactions');
  $router->get('ringless/wallet/amount', 'Ringless\RinglessWalletController@getWalletAmount');

  /* Close Ringless Voicemail*/

  /* Sip Trunk*/
  //trunking reports
  $router->post('trunking/report', 'Sip_trunk\TrunkingCallReportController@getReport');
  $router->get('trunking/connections', 'Sip_trunk\TrunkingCallReportController@getConnection');
  $router->get('trunking/tags', 'Sip_trunk\TrunkingCallReportController@getTags');
  $router->get('trunking/billing_group', 'Sip_trunk\TrunkingCallReportController@getBillingGroup');
  $router->get('trunking/balance', 'Sip_trunk\TrunkingBalanceController@getBalance');
  $router->post('trunking/stripe/save-card', 'Sip_trunk\TrunkingPaymentMethodController@saveCard');
  $router->get('trunking/stripe/get-customer-payment-method', "Sip_trunk\TrunkingPaymentMethodController@getStripeCustomerPaymentMethod");
  $router->post('trunking/stripe/get-payment-method', 'Sip_trunk\TrunkingPaymentMethodController@getPaymentMethod');
  $router->post('trunking/stripe/update-card', 'Sip_trunk\TrunkingPaymentMethodController@updateCard');
  $router->post('trunking/stripe/delete-stripe-payment_method', 'Sip_trunk\TrunkingPaymentMethodController@deletePaymentMethod');
  $router->post('trunking/stripe/recharge', "Sip_trunk\TrunkingRechargeController@recharge");
  $router->get('trunking/wallet/transactions', 'Sip_trunk\TrunkingWalletController@getWalletTransactions');


  /* Close Sip Trunk*/

  /* SMS AI`*/

  $router->get('smsai/campaigns', 'SmsAi\SmsAiCampaignController@index');
  $router->put('smsai/campaign/add', 'SmsAi\SmsAiCampaignController@create');
  $router->get('smsai/campaign/view/{id}', 'SmsAi\SmsAiCampaignController@show');
  $router->post('smsai/campaign/update/{id}', 'SmsAi\SmsAiCampaignController@update');
  $router->post('smsai/campaign/copy', 'SmsAi\SmsAiCampaignController@copyCampaign');
  $router->post('smsai/campaign/update-status', 'SmsAi\SmsAiCampaignController@updateStatus');
  $router->post('smsai/campaign/delete', 'SmsAi\SmsAiCampaignController@deleteCampaign');
  $router->post('smsai/campaign-list', 'SmsAi\SmsAiCampaignController@getCampaignAndList');





  //sms ai lists

  $router->get('smsai/lists', 'SmsAi\SmsAiListController@index');
  $router->put('smsai/list/add', 'SmsAi\SmsAiListController@create');
  $router->get('smsai/list/view/{id}', 'SmsAi\SmsAiListController@show');
  $router->post('smsai/list/update/{id}', 'SmsAi\SmsAiListController@update');
  $router->post('smsai/list/update-status', 'SmsAi\SmsAiListController@updateStatus');
  $router->get('smsai/list/delete/{id}', 'SmsAi\SmsAiListController@delete');
  $router->get('smsai/list/recycle/{id}', 'SmsAi\SmsAiListController@recycle');

  //report

  $router->post('smsai/reports', 'SmsAi\SmsAiReportController@list');
  $router->post('sms-ai-email-report', 'SmsAi\SmsAiReportController@smsAiEmailReportData');
  //daily report

  $router->post('smsai/daily/reports', 'SmsAi\SmsAiDailyReportController@list');

  //billing
  $router->post('smsai/stripe/save-card', 'SmsAi\SmsAiPaymentMethodController@saveCard');
  $router->get('smsai/stripe/get-customer-payment-method', "SmsAi\SmsAiPaymentMethodController@getStripeCustomerPaymentMethod");
  $router->post('smsai/stripe/get-payment-method', 'SmsAi\SmsAiPaymentMethodController@getPaymentMethod');
  $router->post('smsai/stripe/update-card', 'SmsAi\SmsAiPaymentMethodController@updateCard');
  $router->post('smsai/stripe/delete-stripe-payment_method', 'SmsAi\SmsAiPaymentMethodController@deletePaymentMethod');
  $router->post('smsai/stripe/recharge', "SmsAi\SmsAiRechargeController@recharge");
  $router->get('smsai/wallet/transactions', 'SmsAi\SmsAiWalletController@getWalletTransactions');
  $router->get('smsai/wallet/amount', 'SmsAi\SmsAiWalletController@getWalletAmount');


  //smsai templates

  $router->get('smsai/templates', 'SmsAi\SmsAiTemplateController@index');
  $router->put('smsai/template/add', 'SmsAi\SmsAiTemplateController@create');
  $router->get('smsai/template/view/{id}', 'SmsAi\SmsAiTemplateController@show');
  $router->post('smsai/template/update/{id}', 'SmsAi\SmsAiTemplateController@update');
  $router->post('smsai/template/update-status', 'SmsAi\SmsAiTemplateController@updateStatus');
  $router->get('smsai/template/delete/{id}', 'SmsAi\SmsAiTemplateController@delete');
  $router->get('smsai/list-header', 'SmsAi\SmsAiTemplateController@listHeaderSmsAi');
  //drip campaigns
  $router->get('drip-campaigns', 'CrmDripCampaignController@index');
  $router->put('drip-email-template', 'CrmDripCampaignController@create');
  $router->get('drip-campaigns/{id}', 'CrmDripCampaignController@show');
  $router->post('drip-campaigns/{id}', 'CrmDripCampaignController@update');
  $router->post('status-update-drip', 'CrmDripCampaignController@updateDripStatus');
  $router->put('drip-campaign-schedule', 'DripCampaignScheduleController@create');



  $router->get('rvm-cdr-log', 'Ringless\RinglessCdrLogController@index');
  $router->post('rvm-cdr-log', 'Ringless\RinglessCdrLogController@getLog');

  $router->get('ringless-voicemail-drop-log', 'Ringless\RinglessCdrLogController@rvm');








  /* Contact CRM */
  //ai-setting
  Route::get('ai-setting/users-cli-name', 'AiSetting\UsersCliNameController@index');
  Route::post('ai-setting/list-sms', 'AiSetting\SmsListController@index');
  Route::post('ai-setting/show-sms-list', 'AiSetting\DeleteSmsListController@index');
  Route::post('ai-setting/delete-sms', 'AiSetting\DeleteSmsListController@delete');


  #create lenders

  $router->get('lenders', 'LenderController@index');
  $router->get('lender/{id}', 'LenderController@show');
  $router->post('lender/{id}', 'LenderController@update');
  $router->put('lender', 'LenderController@create');
  $router->delete('delete-lender/{id}', 'LenderController@delete');
  $router->post('change-lender-status', 'LenderController@changeLenderStatus');
  $router->get('crm-lender-apis/{id}', 'LenderController@crmLenderApi');

  //crm lead status (legacy routes — kept for backward compat)
  $router->get('leadStatus', 'LeadStatusController@list');
  $router->put('add-lead-status', 'LeadStatusController@create');
  $router->post('update-lead-status/{id}', 'LeadStatusController@update');
  $router->post('change-lead-status', 'LeadStatusController@changeStatus');
  $router->get('delete-lead-status/{id}', 'LeadStatusController@delete');
  $router->post('/lead-status/updateDisplayOrder', 'LeadStatusController@updateDisplayOrder');
  $router->post('change-view-on-dashboard-status', 'LeadStatusController@changeViewOnLead');

  // CRM Lead Status — REST API with pagination
  $router->get('crm/lead-status', 'LeadStatusController@paginatedList');
  $router->post('crm/lead-status', 'LeadStatusController@create');
  $router->put('crm/lead-status/{id}', 'LeadStatusController@update');
  $router->delete('crm/lead-status/{id}', 'LeadStatusController@delete');
  $router->patch('crm/lead-status/{id}/toggle', 'LeadStatusController@toggleStatusById');
  //document
  $router->get('documents', 'DocumentController@list');
  $router->get('documents/{lead_id}', 'DocumentController@listByLeadId');
  $router->get('document/{id}', 'DocumentController@listByDocumentId');
  $router->put('document', 'DocumentController@create');
  $router->get('delete-document/{id}', 'DocumentController@delete');
  $router->post('update-document/{id}', 'DocumentController@update');
  //Document Types
  $router->get('document-types', 'DocumentTypeController@list');
  $router->put('document-type', 'DocumentTypeController@create');
  $router->get('document-value/{type}', 'DocumentTypeController@listByType');

  $router->post('update-document-type/{id}', 'DocumentTypeController@update');
  $router->get('delete-document-type/{id}', 'DocumentTypeController@delete');
  $router->post('change-document-type-status', 'DocumentTypeController@changeDocumentTypeStatus');
  //label
  $router->post('change-label-status', 'LabelController@changeLabelStatus');
  $router->post('change-view-on-lead-status', 'LabelController@changeViewOnLead');

  //Lead Source
  //lead source
  $router->get('lead-source', 'LeadSourceController@list');
  $router->put('add-lead-source', 'LeadSourceController@create');
  $router->post('update-lead-sources/{id}', 'LeadSourceController@update');
  $router->put('add-log-for-lead-source/add', 'LeadSourceController@addLogForLeadSource');


  //lead data

  $router->get('send-data-on-webhook/{id}', 'LeadController@sendDataOnWebhook');
  $router->post('lead-new', 'LeadController@listNew');
  $router->post('sub-lead-new', 'LeadController@sublistNew');

  $router->post('leads', 'LeadController@list');
  $router->put('lead/add', 'LeadController@create');
  $router->post('lead/import', 'LeadController@import');
  $router->put('lead/addLead', 'LeadController@createLead');
  $router->get('lead/{id}', 'LeadController@show');
  $router->post('lead/{id}/edit', 'LeadController@update');
  $router->post('lead-status/{id}/edit', 'LeadController@updateLeadStatus');
  $router->get('lead/{id}/delete', 'LeadController@delete');
  $router->post('lead/{id}/view', 'LeadController@view');
  $router->get('domain-list', 'LeadController@domainList');
  $router->put('lead/copy', 'LeadController@copy');
  $router->get('documents/lead/{id}', 'LeadController@getLeadData');
  $router->post('leads/add/opener', 'LeadController@addOpener');
  $router->post('leads/add/closer', 'LeadController@addCloser');
  $router->get('fcs/{id}', 'FcsController@index');
  $router->post('fcs-lendor', 'FcsController@addBank');
  $router->get('eligible-lender/{lead_id}/{bank_id}', 'FcsController@eligibleLender');
  $router->post('lender-matrix/{lead_id}/{bank_id}', 'FcsController@LenderList');
  $router->get('lender-matrix/{lead_id}', 'FcsController@GetLenderList');
  $router->post('leadTask/add', 'LeadController@addLeadTask');
  $router->get('crm-scheduled-task/{lead_id}', 'LeadController@CrmScheduledTask');
  $router->get('crm-scheduled-task/{lead_id}/{id}/delete', 'LeadController@deleteTask');

  //get send lead to lenders list
  $router->get('send-lead-to-lenders/{id}', 'LeadController@SendLeadToLenders');
  $router->get('lender-status', 'LeadController@LenderStatus');
  $router->post('lender-status/{id}/edit', 'LeadController@SubmitLenderStatus');
  $router->put('lender/notes/add', 'LeadController@addNotes');
  $router->get('showlenders/{id}', 'LeadController@showNotes');
  $router->post('lender/notes/edit', 'LeadController@editNotes');
  $router->get('user/{id}', 'UserController@show');



  //labels
  $router->get('crm-labels', 'CrmLabelController@list');
  $router->get('crm-view-on-leads', 'CrmLabelController@viewOnLead');
  $router->get('crm-labels-order-by-title', 'CrmLabelController@listOrderByTtile');

  $router->put('crm-add-label', 'CrmLabelController@create');
  $router->post('crm-update-label/{id}', 'CrmLabelController@update');
  $router->get('crm-delete-label/{id}', 'CrmLabelController@delete');
  $router->post('crm-change-label-status', 'CrmLabelController@changeLabelStatus');
  $router->post('crm-change-view-on-lead-status', 'CrmLabelController@changeViewOnLead');

  $router->post('/crm-label/updateDisplayOrder', 'CrmLabelController@updateDisplayOrder');

  // REST-style Lead Fields API
  $router->get('crm/lead-fields',         'CrmLabelController@leadFieldsList');
  $router->post('crm/lead-fields',         'CrmLabelController@leadFieldsCreate');
  $router->post('crm/lead-fields/{id}',    'CrmLabelController@leadFieldsUpdate');
  $router->delete('crm/lead-fields/{id}',  'CrmLabelController@leadFieldsDelete');

  // ── Tenant private file serving ─────────────────────────────────────────────
  $router->get('crm/tenant-file/{subdir}/{filename}', 'TenantFileController@serveFile');

  // ── Company Settings ────────────────────────────────────────────────────────
  $router->get('crm/company-settings',         'CompanyDetailController@getSettings');
  $router->put('crm/company-settings',         'CompanyDetailController@updateSettings');
  $router->post('crm/company-settings/logo',   'CompanyDetailController@uploadLogo');
  $router->delete('crm/company-settings/logo', 'CompanyDetailController@deleteLogo');

  // ── Affiliate Link Management ───────────────────────────────────────────────
  $router->get('crm/affiliate/my-link',       'CompanyDetailController@getMyAffiliateLink');
  $router->post('crm/affiliate/generate-code','CompanyDetailController@generateMyCode');
  $router->get('crm/affiliate/users',         'CompanyDetailController@listAffiliateUsers');
  $router->get('crm/lead/{id}/merchant-link', 'CompanyDetailController@getLeadMerchantLink');

  //notes and updates

  $router->get('notifications-crm', 'CrmNotificationController@list');
  $router->put('notification-crm/add', 'CrmNotificationController@create');
  $router->get('notification-crm/{lead_id}/{type}', 'CrmNotificationController@listbyLeadId');

  //email-templates

  $router->get('crm-email-templates', 'CrmEmailTemplateController@list');
  $router->get('crm-email-template/{id}', 'CrmEmailTemplateController@show');
  // $router->delete('crm-delete-smtp/{id}', 'SmtpSettingController@delete');
  $router->put('crm-add-email-template', 'CrmEmailTemplateController@create');
  $router->post('crm-email-template/{id}', 'CrmEmailTemplateController@update');
  $router->post('crm-change-email-template-status', 'CrmEmailTemplateController@changeEmailTemplateStatus');
  $router->get('crm-delete-email-template/{id}', 'CrmEmailTemplateController@delete');
  //crm-sms-templates
  $router->get('crm-sms-template', 'CrmSmsTemplateController@list');
  $router->get('crm-sms-template/{id}', 'CrmSmsTemplateController@show');
  $router->put('crm-add-sms-template', 'CrmSmsTemplateController@create');
  $router->post('crm-sms-template/{id}', 'CrmSmsTemplateController@update');
  $router->post('crm-change-sms-template-status', 'CrmSmsTemplateController@changeSmsTemplateStatus');
  $router->get('crm-delete-sms-template/{id}', 'CrmSmsTemplateController@delete');
  //custom-templates

  $router->get('crm-custom-templates', 'CrmCustomTemplateController@list');
  $router->get('crm-custom-template/{id}', 'CrmCustomTemplateController@show');
  // $router->delete('crm-delete-smtp/{id}', 'SmtpSettingController@delete');
  $router->put('crm-add-custom-template', 'CrmCustomTemplateController@create');
  $router->post('crm-custom-template/{id}', 'CrmCustomTemplateController@update');
  $router->post('crm-change-custom-template-status', 'CrmCustomTemplateController@changeCustomTemplateStatus');
  $router->get('crm-delete-custom-template/{id}', 'CrmCustomTemplateController@delete');


  //send email
  $router->get('crm-email-template/{id}/{list_id}/{lead_id}', 'CrmEmailTemplateController@viewEmailPopup');
  $router->get('crm-custom-template/{id}/{list_id}/{lead_id}/{file_type}', 'CrmCustomTemplateController@viewPDFPopup');

  $router->get('label-data-crm/{id}/{list_id}/{lead_id}', 'CrmEmailTemplateController@labelValue');
  $router->post('send-email-crm/generic', 'MailController@sendEmailGenericCRM');


  $router->get('users', 'ExtensionController@getExtensionListCRMNew');
  $router->get('users-list-new', 'ExtensionController@getExtensionListCRMNew');


  //merchant links
  $router->get('label-list/{clientId}', 'MerchantController@labelList');
  $router->get('lead-details/{leadId}/{clientId}', 'MerchantController@leadDetails');
  $router->get('lead-details-by-token/{leadId}/{clientId}', 'MerchantController@leadDetailsByToken');

  $router->get('document-types-list/{clientId}', 'MerchantController@typesList');
  $router->get('type-value/{type}/{clientId}', 'MerchantController@typeValueDocument');
  $router->get('document-list/{leadId}/{clientId}', 'MerchantController@documentList');
  $router->put('save-document/{clientId}', 'MerchantController@create');
  $router->post('edit-lead/{leadId}/{clientId}/edit', 'MerchantController@update');
  $router->put('add-notification/add/{leadId}/{clientId}', 'MerchantController@createNotification');
  $router->post('send-email/generic-merchant', 'MerchantController@sendEmailGeneric');

  //crm system setting 
  $router->get('crm-system-setting', 'CrmSystemSettingController@list');
  $router->post('crm-system-setting', 'CrmSystemSettingController@create');
  $router->post('update-system-setting/{id}', 'CrmSystemSettingController@update');

  $router->get('company-columns', 'CrmSystemSettingController@companyColumns');
  //crm email setting 
  $router->get('crm-email-setting', 'CrmEmailSettingController@list');
  $router->post('crm-email-setting', 'CrmEmailSettingController@create');
  $router->post('update-crm-email-setting/{id}', 'CrmEmailSettingController@update');
  //crm dasboard
  $router->get('dashboard-lead-status', 'CrmdashboardController@index');

  // MCA Dashboard Metrics
  $router->post('mca/dashboard-metrics', 'CrmdashboardController@getMcaDashboardMetrics');

  /* Close Contact CRM */


  #lender label setting apis

  $router->get('lender-label-api-setting', 'LenderApiLabelController@index');
  $router->post('lender-label-api-setting', 'LenderApiLabelController@save');
#pusher
//$router->get('/pusher-test', 'TestController@test');

  #pdf reader
  $router->get('pdf-reader-setting', 'PdfReaderController@index');
  $router->post('update-pdf-reader', 'PdfReaderController@update');
  $router->post('upload-pdf-reader', 'PdfReaderController@upload');
  //schedule
  $router->post('schedule', 'ScheduleController@index');
  $router->post('save-schedule', 'ScheduleController@store');
  $router->post('schedule/delete-schedule', 'ScheduleController@deleteSchedule');
    $router->get('prompts', 'PromptController@index');
    $router->post('prompts', 'PromptController@store');
    $router->get('prompts/{id}', 'PromptController@show');
    $router->post('prompts/update/{id}', 'PromptController@update');
    $router->post('prompts/delete/{id}', 'PromptController@destroy');

    $router->post('prompts/{id}/functions', 'PromptController@saveFunctions');

  // Attendance Management
  $router->post('attendance/clock-in', 'AttendanceController@clockIn');
  $router->post('attendance/clock-out', 'AttendanceController@clockOut');
  $router->get('attendance/status', 'AttendanceController@getStatus');
  $router->post('attendance/break/start', 'AttendanceController@startBreak');
  $router->post('attendance/break/end', 'AttendanceController@endBreak');
  $router->post('attendance/list', 'AttendanceController@getAttendanceList');
  $router->post('attendance/update', 'AttendanceController@updateAttendance');
  $router->get('attendance/my-attendance', 'AttendanceController@getMyAttendance');

  // Shift Management
  $router->post('shift/list', 'ShiftController@getShiftList');
  $router->post('shift/add', 'ShiftController@addShift');
  $router->post('shift/update', 'ShiftController@updateShift');
  $router->post('shift/delete', 'ShiftController@deleteShift');
  $router->post('shift/assign', 'ShiftController@assignShiftToUser');

  // Attendance Reports
  $router->post('attendance/report/daily', 'AttendanceReportController@getDailyReport');
  $router->post('attendance/report/weekly', 'AttendanceReportController@getWeeklyReport');
  $router->post('attendance/report/monthly', 'AttendanceReportController@getMonthlyReport');
  $router->post('attendance/report/summary', 'AttendanceReportController@getSummaryReport');
  $router->post('attendance/report/alerts', 'AttendanceReportController@getLateEarlyAlerts');

  // ── Workforce Management System ──────────────────────────────────────────
  // Phase 1: Supervisor real-time dashboard
  $router->get('workforce/dashboard',              'WorkforceDashboardController@index');
  $router->get('workforce/agent-status/{id}',      'WorkforceDashboardController@agentStatus');

  // Phase 3: Dialer status sync (supervisor override)
  $router->post('workforce/agent-status',          'AgentStatusController@update');
  $router->get('workforce/agents-online',          'AgentStatusController@agentsOnline');

  // Phase 4: Campaign staffing requirements
  $router->get('workforce/campaign-staffing',      'CampaignStaffingController@index');
  $router->post('workforce/campaign-staffing',     'CampaignStaffingController@upsert');
  $router->delete('workforce/campaign-staffing/{campaign_id}', 'CampaignStaffingController@destroy');

  // Phase 5: Break throttle policies
  $router->get('workforce/break-policy',           'BreakPolicyController@index');
  $router->post('workforce/break-policy',          'BreakPolicyController@upsert');
  $router->delete('workforce/break-policy/{id}',   'BreakPolicyController@destroy');

  // Phase 8: Workforce reports (productivity, staffing, idle)
  $router->post('workforce/report/productivity',   'WorkforceReportController@productivity');
  $router->post('workforce/report/staffing',       'WorkforceReportController@staffing');
  $router->post('workforce/report/idle',           'WorkforceReportController@idle');

  // Phase 9: Workforce analytics charts
  $router->get('workforce/analytics/attendance-trend',    'WorkforceAnalyticsController@attendanceTrend');
  $router->get('workforce/analytics/call-vs-availability','WorkforceAnalyticsController@callVsAvailability');
  $router->get('workforce/analytics/break-distribution',  'WorkforceAnalyticsController@breakDistribution');
  $router->get('workforce/analytics/utilization-trend',   'WorkforceAnalyticsController@utilizationTrend');
  $router->get('workforce/analytics/leaderboard',         'WorkforceAnalyticsController@leaderboard');

  // ── CRM HubSpot-Style Upgrade Routes ─────────────────────────────────────

  // Activity Timeline
  $router->get('crm/lead/{id}/activity',            'CrmLeadActivityController@timeline');
  $router->put('crm/lead/{id}/activity',            'CrmLeadActivityController@addManualEntry');
  $router->post('crm/lead/{id}/activity/{aid}/pin', 'CrmLeadActivityController@pin');
  $router->get('crm/lead/{id}/status-history',      'CrmLeadStatusHistoryController@index');

  // Pipeline Board & Saved Views
  $router->get('crm/pipeline/board',            'CrmPipelineController@board');
  $router->patch('crm/pipeline/leads/{id}/move','CrmPipelineController@moveLead');
  $router->get('crm/pipeline/views',            'CrmPipelineController@listViews');
  $router->put('crm/pipeline/views',            'CrmPipelineController@createView');
  $router->post('crm/pipeline/views/{id}',      'CrmPipelineController@updateView');
  $router->delete('crm/pipeline/views/{id}',   'CrmPipelineController@deleteView');

  // Approvals (review requires user_level >= 5)
  $router->put('crm/lead/{id}/approval/request',          'CrmApprovalController@request');
  $router->post('crm/lead/{id}/approval/{aid}/review',    'CrmApprovalController@review');
  $router->post('crm/lead/{id}/approval/{aid}/withdraw',  'CrmApprovalController@withdraw');
  $router->get('crm/lead/{id}/approvals',                 'CrmApprovalController@list');
  $router->get('crm/approvals',                           'CrmApprovalController@listAll');

  // Affiliate Links
  $router->get('crm/affiliate-links',             'CrmAffiliateLinkController@list');
  $router->put('crm/affiliate-links',             'CrmAffiliateLinkController@create');
  $router->post('crm/affiliate-links/{id}',       'CrmAffiliateLinkController@update');
  $router->delete('crm/affiliate-links/{id}',     'CrmAffiliateLinkController@deactivate');
  $router->get('crm/affiliate-links/{id}/stats',  'CrmAffiliateLinkController@stats');

  // Merchant Portals
  $router->post('crm/lead/{id}/merchant-portal/generate',     'CrmMerchantPortalController@generate');
  $router->get('crm/lead/{id}/merchant-portal',               'CrmMerchantPortalController@show');
  $router->post('crm/lead/{id}/merchant-portal/{pid}/revoke', 'CrmMerchantPortalController@revoke');

  // Bulk Operations
  $router->post('crm/leads/bulk/assign',         'CrmBulkController@bulkAssign');
  $router->post('crm/leads/bulk/status-change',  'CrmBulkController@bulkStatusChange');
  $router->post('crm/leads/bulk/delete',         'CrmBulkController@bulkDelete');
  $router->post('crm/leads/bulk/export',         'CrmBulkController@bulkExport');

  // Advanced Search
  $router->post('crm/leads/search', 'CrmSearchController@search');

  // Analytics
  $router->get('crm/analytics/status-distribution', 'CrmAnalyticsController@statusDistribution');
  $router->get('crm/analytics/lead-velocity',        'CrmAnalyticsController@leadVelocity');
  $router->get('crm/analytics/agent-performance',    'CrmAnalyticsController@agentPerformance');
  $router->get('crm/analytics/conversion-funnel',    'CrmAnalyticsController@conversionFunnel');
  $router->get('crm/analytics/lender-performance',   'CrmAnalyticsController@lenderPerformance');

  // Documents
  $router->get('crm/lead/{id}/documents',          'CrmDocumentController@index');
  $router->post('crm/lead/{id}/documents',         'CrmDocumentController@store');
  $router->delete('crm/lead/{id}/documents/{did}', 'CrmDocumentController@destroy');

  // Send to Lender (legacy single-lender)
  $router->get('crm/lead/{id}/lender-submissions', 'LeadController@lenderSubmissions');
  $router->post('crm/lead/{id}/send-to-lender',    'LeadController@sendToLender');

  // Enhanced Lender Submission System
  $router->post('crm/lead/{id}/submit-application',                       'LeadController@submitApplication');
  $router->get('crm/lead/{id}/lender-submissions/enhanced',               'LeadController@enhancedLenderSubmissions');
  $router->post('crm/lead/{id}/submissions/{subId}/response',             'LeadController@updateSubmissionResponse');

  // PDF Application Generator
  $router->get('crm/lead/{id}/render-pdf',                                'LeadController@renderPdf');

  // ── Offers ─────────────────────────────────────────────────────────────────
  $router->get('crm/lead/{id}/offers',              'CrmOfferController@index');
  $router->put('crm/lead/{id}/offers',              'CrmOfferController@store');
  $router->post('crm/lead/{id}/offers/{oid}',       'CrmOfferController@update');
  $router->delete('crm/lead/{id}/offers/{oid}',     'CrmOfferController@destroy');
  $router->post('crm/lead/{id}/offers/{oid}/accept','CrmOfferController@accept');

  // ── Stips ──────────────────────────────────────────────────────────────────
  $router->get('crm/lead/{id}/stips',               'CrmStipController@index');
  $router->put('crm/lead/{id}/stips',               'CrmStipController@store');
  $router->post('crm/lead/{id}/stips/bulk',         'CrmStipController@bulkCreate');
  $router->post('crm/lead/{id}/stips/{sid}',        'CrmStipController@update');
  $router->delete('crm/lead/{id}/stips/{sid}',      'CrmStipController@destroy');

  // ── Funded Deal ─────────────────────────────────────────────────────────────
  $router->get('crm/lead/{id}/funded-deal',                          'CrmFundedDealController@show');
  $router->put('crm/lead/{id}/funded-deal',                          'CrmFundedDealController@store');
  $router->post('crm/lead/{id}/funded-deal/{did}',                   'CrmFundedDealController@update');
  $router->post('crm/lead/{id}/funded-deal/{did}/mark-renewed',      'CrmFundedDealController@markRenewed');

  // ── Merchant Positions ──────────────────────────────────────────────────────
  $router->get('crm/lead/{id}/positions',           'CrmPositionController@index');
  $router->put('crm/lead/{id}/positions',           'CrmPositionController@store');
  $router->delete('crm/lead/{id}/positions/{pid}',  'CrmPositionController@destroy');

  // ── Compliance ──────────────────────────────────────────────────────────────
  $router->get('crm/lead/{id}/compliance',             'CrmComplianceController@index');
  $router->put('crm/lead/{id}/compliance',             'CrmComplianceController@store');
  $router->post('crm/lead/{id}/compliance/{cid}',      'CrmComplianceController@update');
  $router->get('crm/advance-registry/search',          'CrmComplianceController@searchAdvanceRegistry');
  $router->get('crm/lead/{id}/stacking-warning',       'CrmComplianceController@stackingWarning');

  // ── Automations ─────────────────────────────────────────────────────────────
  $router->get('crm/automations',               'CrmAutomationController@index');
  $router->put('crm/automations',               'CrmAutomationController@store');
  $router->post('crm/automations/{id}',         'CrmAutomationController@update');
  $router->delete('crm/automations/{id}',       'CrmAutomationController@destroy');
  $router->patch('crm/automations/{id}/toggle', 'CrmAutomationController@toggle');
  $router->get('crm/automations/{id}/logs',     'CrmAutomationController@logs');

  // ── SMS Inbox ───────────────────────────────────────────────────────────────
  $router->get('crm/sms/conversations',                    'CrmSmsInboxController@getConversations');
  $router->get('crm/sms/conversations/{id}/messages',      'CrmSmsInboxController@getMessages');
  $router->post('crm/sms/conversations/{id}/send',         'CrmSmsInboxController@sendMessage');
  $router->post('crm/sms/conversations/{id}/read',         'CrmSmsInboxController@markRead');
  $router->get('crm/pdf/placeholders',                                    'LeadController@pdfPlaceholders');
});


// Public CRM affiliate token check (no auth required — increments click count)
// Rate-limited to 60 requests/min per IP to prevent click inflation attacks
$router->get('crm/affiliate/{token}/check', ['middleware' => 'throttle:60,1', 'uses' => 'CrmAffiliateLinkController@checkByToken']);

//phone charges deduction
$router->post('call-billing', "CallBillingController@prepareBill");

//sms api receiveing from external
$router->post('receive-sms', 'SmsController@smsResponse');
$router->get('receive-sms', 'SmsController@smsResponse');
$router->get('send-test-sms', 'SmsController@sendTestSms');

$router->group(['middleware' => ['websiteclient']], function () use ($router) {
  $router->get('otp/email', 'OtpController@requestEmailOtp');
  $router->get('otp/phone', 'OtpController@requestPhoneOtp');
  $router->post('otp/verify', 'OtpController@verifyOtp');
});
$router->post('otp/mobile/send', 'OtpController@OtpMobile');
$router->post('otp/mobile/verify', 'OtpController@VerifyOtpMobile');


$router->get('validate/email', 'OtpController@validateEmail');
$router->get('validate/phone', 'OtpController@validatePhone');
$router->get('validate/company', 'OtpController@validateCompany');

$router->post('validate-otp', 'OtpController@verifyOtpLogin');



#todo: move to the token based auth
$router->put('prospect-signup', 'ClientController@prospectSignup');
$router->get('packages', 'SubscriptionController@list');
$router->put('prospect/subscribe-package', 'SubscriptionController@saveProspectPackage');
$router->put('prospect/save-initial-data', 'SubscriptionController@saveInitialData');

$router->post('live-call-activity', "CallLeadController@getLiveCallActivity");
$router->get('predictive-dial-call', "CallPredictiveDialController@index");

$router->get('predictive-dial-call-all-client', "CallPredictiveDialAllClientController@index");
$router->get('inbound-call-popup-notification', "InboundCallPopUpController@index");
$router->get('inbound-call-popup-received', "InboundCallPopUpController@receivedInboundCallPopUp");
$router->get('inbound-call-popup-completed', "InboundCallPopUpController@completedInboundCallPopUp");
$router->post('inbound-call-popup', "InboundCallPopUpController@inboundCallPopup");

//forgot password
$router->get('forgot-password-email/email', 'OtpController@requestForgotPasswordEmail');
$router->get('check-forgot-password-link/{token}', 'UserController@checkForgotPasswordLink');
$router->post('reset-password', 'UserController@resetPassword');

$router->post('forgot-password', 'UserController@forgotPassword');
$router->get('verify-token/{token}', 'UserController@verifyResetToken');
$router->post('resetPasswordUser', 'UserController@resetPasswordUser');
$router->post('reset_password', 'UserController@resetPasswordUser');   // alias for React frontend
$router->post('forgot-password-mobile', 'UserController@forgotPasswordMobile');
$router->post('verify-token-mobile/{otp_id}', 'UserController@verifyResetTokenMobile');
$router->post('resetPasswordUserMobile', 'UserController@resetPasswordUserMobile');
$router->post('forgot-password-resend', 'UserController@forgotPasswordMobileResend');


// ─── Agent Management (JWT required, admin-level 7+) ──────────────────────────
$router->group(['middleware' => ['jwt.auth', 'audit.log', 'tenant'], 'prefix' => 'agents'], function () use ($router) {
    $router->get('/',                    'AgentController@index');           // GET  /agents
    $router->get('/roles',               'AgentController@roles');           // GET  /agents/roles
    $router->post('/',                   'AgentController@store');           // POST /agents
    $router->get('/{id:[0-9]+}',         'AgentController@show');            // GET  /agents/{id}
    $router->put('/{id:[0-9]+}',         'AgentController@update');          // PUT  /agents/{id}
    $router->delete('/{id:[0-9]+}',      'AgentController@destroy');         // DELETE /agents/{id}
    $router->post('/{id:[0-9]+}/activate',       'AgentController@activate');       // POST /agents/{id}/activate
    $router->post('/{id:[0-9]+}/reset-password', 'AgentController@resetPassword');  // POST /agents/{id}/reset-password
});

// ─── Onboarding Wizard (JWT required) ─────────────────────────────────────────
$router->group(['middleware' => ['jwt.auth', 'audit.log', 'tenant'], 'prefix' => 'onboarding'], function () use ($router) {
    $router->get('/',        'OnboardingController@getProgress');  // GET  /onboarding
    $router->post('/complete', 'OnboardingController@completeStep'); // POST /onboarding/complete
    $router->post('/reset',    'OnboardingController@reset');        // POST /onboarding/reset (admin)
});

//check cli report

$router->post('check-cli-report', "CheckCliReportController@index");

//billing rate charge
$router->post('billing-charge', "BillingChargeController@index");

$router->get('outbound-ai-dial-call-all-client', "CallOutboundAIDialAllClientController@index");

$router->post('lang', "GoogleLanguageController@lang");
$router->get('/voice-audio', 'GoogleClientController@voiceAudio');
$router->get('/voice-ai-extension-user-audio', 'VoiceAiExtensionUserController@voiceAi');


/* CRM Webphone Example */
$router->get('asterisk-login', 'DialerController@asteriskLoginCRM');
$router->get('asterisk-hang-up', 'DialerController@hangUpCRM');




//website

$router->get('otp/phone-otp', 'OtpController@requestPhoneOtpWebsite');
$router->get('otp/email-otp', 'OtpController@requestEmailOtpWebsite');

$router->post('website-lead-submit', 'ClientController@WebsiteLeadSignup');
$router->get('verify-token-email/{token}/{expire}', 'UserController@verifyEmailToken');



//set app_extension for all

$router->get('set-app-extension', 'DidsController@setAppExtension');


//merchant urls
$router->get('crm-system-settings/{clientId}', 'MerchantController@companyList');
$router->get('lead-details-by-token/{leadId}/{clientId}', 'MerchantController@leadDetailsByToken');
$router->get('label-list/{clientId}', 'MerchantController@labelList');
$router->get('document-types-list/{clientId}', 'MerchantController@typesList');
$router->get('document-list/{leadId}/{clientId}', 'MerchantController@documentList');
$router->post('edit-lead/{leadId}/{clientId}/edit', 'MerchantController@update');
$router->put('add-notification/add/{leadId}/{clientId}', 'MerchantController@createNotification');
$router->put('document/{clientId}', 'MerchantController@create');
$router->get('crm-custom-template-merchant/{id}/{list_id}/{lead_id}/{parent_id}/{file_type}', 'CrmCustomTemplateController@viewPDFPopupMerchant');

//labels
$router->get('crm-labels-affiliates/{client_id}', 'CrmLabelController@listAffiliates');
$router->put('affiliate/lead/add/{client_id}/{extension_id}', 'AffiliateController@create');
$router->get('check-affiliate-link/{client_id}/{extension_id}/{token_url}', 'AffiliateController@checkAffiliateLink');
$router->put('save-document-affiliate/{clientId}', 'AffiliateController@createDocument');
$router->put('add-notification-affiliate/add/{leadId}/{clientId}', 'AffiliateController@createNotification');

//state list
$router->get('state-list', 'AreaCodeController@groupByAreaCode');



//ringless voicemail drop code

$router->get('ringless-voicemail-drop-testing-api', 'RinglessVMTestController@index');

$router->get('rvm-drop-by-sip-trunk', 'RinglessVMBySipNameController@index');
$router->get('ringless-voicemail-drop-status-not-success', 'RinglessVMNotSuccessController@notSuccess');

$router->get('ringless-voicemail-drop', 'RinglessVMBySipNameController@index');
//$router->get('ringless-voicemail-drop', 'RinglessVMController@index');

$router->get('rvm-status', 'RinglessVMBySipNameController@rvmStatus');



$router->get('failed-rvm-drop-by-sip-trunk', 'RinglessVMBySipNameController@failedRvmData');

$router->post('rvm-callback-cdr', 'RinglessVMBySipNameController@rvmCallbackCdr');

//instant queue RVM

$router->get('rvm-drop-by-sip-trunk-instant-queue', 'RinglessVMBySipNameInstantQueueController@index');





$router->get('ringless-data', 'RinglessVMController@report');
$router->post('ringless-data', 'RinglessVMController@reportToAdmin');

$router->get('ringless-voicemail-drop-testing-one', 'RinglessVMControllerTestOne@index');

$router->get('ringless-testing', 'JobController@sendJobs');





//voicemail send to email

$router->post('send-vm-to-email', 'SendVoicemailToEmailController@index');



//sms ai data stored api
$router->get('open-ai-setting-website', 'OpenAiController@listWebsite');

$router->post('receive-sms-ai', 'OpenAiController@store');

$router->post('sms-ai-report-email', 'OpenAiController@smsEmailReport');


$router->get('crm-custom-template-affiliate/{parent_id}/{id}/{list_id}/{lead_id}/{file_type}', 'CrmCustomTemplateController@viewPDFPopupAffiliate');



//sip gateways for external clients

$router->post('sip-gateway/create', 'SipGateways\SipGatewaysController@create');

//ringless billing redeem api

$router->get('ringless/wallet/redeem', 'Ringless\RinglessWalletController@redeemAmount');


//api log table in master


$router->post('api-logs', 'ApiLogsController@create');

$router->get('document-value-merchant/{type}/{client_id}', 'DocumentTypeController@listByTypeMerchant');


$router->get('transcription-conversion-api', 'TranscriptionController@index');

$router->get('ai-coach-api', 'AiCoachController@index');


#new apis for phonify
// Gmail OAuth callback (no auth required - user info from state parameter)
$router->get('gmail/callback', 'GmailOAuthController@callbackNoAuth');

// Gmail routes (auth required)
$router->group(['middleware' => ['jwt.auth', 'audit.log', 'tenant'], 'prefix' => 'gmail'], function () use ($router) {
    // OAuth
    $router->get('connect', 'GmailOAuthController@connect');
    $router->post('disconnect', 'GmailOAuthController@disconnect');
    $router->get('status', 'GmailOAuthController@status');
    $router->post('refresh-token', 'GmailOAuthController@refreshToken');

    // Watch (push notifications)
    $router->post('watch/setup', 'GmailOAuthController@setupWatch');
    $router->post('watch/stop', 'GmailOAuthController@stopWatch');
    $router->get('watch/status', 'GmailOAuthController@watchStatus');

    // Notification Settings
    $router->get('settings', 'GmailNotificationSettingsController@show');
    $router->post('settings', 'GmailNotificationSettingsController@update');
    $router->get('channels', 'GmailNotificationSettingsController@getChannels');
    $router->post('test', 'GmailNotificationSettingsController@testNotification');
    $router->get('logs', 'GmailNotificationSettingsController@getLogs');

    // Mailbox
    $router->get('mailbox', 'GmailMailboxController@list');
    $router->get('mailbox/labels', 'GmailMailboxController@labels');
    $router->get('mailbox/{messageId}', 'GmailMailboxController@show');
    $router->post('mailbox/send', 'GmailMailboxController@send');
    $router->post('mailbox/{messageId}/star', 'GmailMailboxController@star');
    $router->post('mailbox/{messageId}/unstar', 'GmailMailboxController@unstar');
    $router->post('mailbox/{messageId}/trash', 'GmailMailboxController@trash');
    $router->delete('mailbox/{messageId}', 'GmailMailboxController@delete');
    $router->post('mailbox/{messageId}/read', 'GmailMailboxController@markAsRead');
    $router->post('mailbox/{messageId}/unread', 'GmailMailboxController@markAsUnread');
});

// Gmail Pub/Sub Webhook (no auth required - Google sends notifications here)
$router->post('gmail/webhook', 'GmailPubSubWebhookController@handle');
$router->get('gmail/webhook/ping', 'GmailPubSubWebhookController@ping');

// ─── Unified Integrations API (Profile page) ──────────────────────────────────
// Google Calendar OAuth callback (no auth required - user info from state)
$router->get('integrations/google-calendar/callback', 'GoogleCalendarOAuthController@callbackNoAuth');

// JWT-protected integration endpoints
$router->group(['middleware' => ['jwt.auth', 'tenant']], function () use ($router) {
    $router->get('integrations',           'IntegrationController@index');
    $router->post('connect-integration',   'IntegrationController@connect');
    $router->post('disconnect-integration','IntegrationController@disconnect');
});

// ─── Google Calendar Events API ───────────────────────────────────────────────
$router->group(['middleware' => ['jwt.auth', 'tenant'], 'prefix' => 'calendar'], function () use ($router) {
    $router->get('status',              'GoogleCalendarEventsController@status');
    $router->get('events',              'GoogleCalendarEventsController@list');
    $router->post('events',             'GoogleCalendarEventsController@create');
    $router->put('events/{eventId}',    'GoogleCalendarEventsController@update');
    $router->delete('events/{eventId}', 'GoogleCalendarEventsController@delete');
});

// ─── Twilio Telecom Infrastructure ────────────────────────────────────────────
// JWT-protected management endpoints (Admin level enforced in controllers)
$router->group(['middleware' => ['jwt.auth', 'tenant']], function () use ($router) {

    // Account management
    $router->post('twilio/connect',              'TwilioAccountController@connect');
    $router->get('twilio/account',               'TwilioAccountController@getAccount');
    $router->delete('twilio/account',            'TwilioAccountController@disconnect');
    $router->post('twilio/subaccount',           'TwilioAccountController@createSubaccount');
    $router->get('twilio/subaccounts',           'TwilioAccountController@listSubaccounts');
    $router->post('twilio/subaccount/suspend',   'TwilioAccountController@suspendSubaccount');
    $router->get('twilio/usage',                 'TwilioAccountController@usage');

    // Phone numbers
    $router->get('twilio/numbers/search',        'TwilioNumberController@search');
    $router->post('twilio/numbers/purchase',     'TwilioNumberController@purchase');
    $router->delete('twilio/numbers/{sid}',      'TwilioNumberController@release');
    $router->get('twilio/numbers',               'TwilioNumberController@list');
    $router->post('twilio/numbers/assign',       'TwilioNumberController@assignToCampaign');
    $router->get('twilio/numbers/campaign/{id}', 'TwilioNumberController@getByCampaign');
    $router->post('twilio/numbers/unassign',     'TwilioNumberController@unassignFromCampaign');

    // Voice calls
    $router->post('twilio/calls/make',           'TwilioCallController@makeCall');
    $router->get('twilio/calls',                 'TwilioCallController@list');
    $router->get('twilio/calls/{sid}',           'TwilioCallController@getById');
    $router->post('twilio/calls/sync',             'TwilioCallController@sync');
    $router->get('twilio/recordings',            'TwilioCallController@getRecordings');

    // SMS
    $router->post('twilio/sms/send',             'TwilioSmsController@send');
    $router->post('twilio/sms/bulk',             'TwilioSmsController@bulkSend');
    $router->get('twilio/sms',                   'TwilioSmsController@list');

    // SIP Trunks
    $router->get('twilio/trunks',                'TwilioTrunkController@list');
    $router->post('twilio/trunks',               'TwilioTrunkController@create');
    $router->delete('twilio/trunks/{sid}',       'TwilioTrunkController@delete');
    $router->post('twilio/trunks/{sid}/url',     'TwilioTrunkController@updateOriginationUrl');
    $router->post('twilio/trunks/sync',            'TwilioTrunkController@sync');
});

// Twilio Webhooks — signature validated, no JWT
$router->group(['middleware' => ['twilio.webhook']], function () use ($router) {
    $router->post('twilio/webhook/inbound-call',     'TwilioWebhookController@inboundCall');
    $router->post('twilio/webhook/inbound-sms',      'TwilioWebhookController@inboundSms');
    $router->post('twilio/webhook/call-status',      'TwilioWebhookController@callStatus');
    $router->post('twilio/webhook/recording-status', 'TwilioWebhookController@recordingStatus');
});

// $router->post('/api/auth/create-user', 'AuthenticationController@createUser');



// ─── Plivo Telecom Infrastructure ─────────────────────────────────────────────
// JWT-protected management endpoints (Admin level enforced in controllers)
$router->group(['middleware' => ['jwt.auth', 'tenant']], function () use ($router) {

    // Account management
    $router->post('plivo/connect',                'PlivoAccountController@connect');
    $router->get('plivo/account',                 'PlivoAccountController@getAccount');
    $router->delete('plivo/account',              'PlivoAccountController@disconnect');
    $router->post('plivo/subaccount',             'PlivoAccountController@createSubaccount');
    $router->get('plivo/subaccounts',             'PlivoAccountController@listSubaccounts');
    $router->post('plivo/subaccount/suspend',     'PlivoAccountController@suspendSubaccount');
    $router->get('plivo/usage',                   'PlivoAccountController@usage');

    // Phone numbers
    $router->get('plivo/numbers/search',          'PlivoNumberController@search');
    $router->post('plivo/numbers/purchase',       'PlivoNumberController@purchase');
    $router->delete('plivo/numbers/{number}',     'PlivoNumberController@release');
    $router->get('plivo/numbers',                 'PlivoNumberController@list');
    $router->post('plivo/numbers/assign',         'PlivoNumberController@assignToCampaign');
    $router->get('plivo/numbers/campaign/{id}',   'PlivoNumberController@getByCampaign');
    $router->post('plivo/numbers/unassign',       'PlivoNumberController@unassignFromCampaign');

    // Voice calls
    $router->post('plivo/calls/make',             'PlivoCallController@makeCall');
    $router->post('plivo/calls/{uuid}/hangup',    'PlivoCallController@hangup');
    $router->get('plivo/calls',                   'PlivoCallController@list');
    $router->get('plivo/calls/{uuid}',            'PlivoCallController@getById');
    $router->get('plivo/recordings',              'PlivoCallController@getRecordings');

    // SMS
    $router->post('plivo/sms/send',               'PlivoSmsController@send');
    $router->post('plivo/sms/bulk',               'PlivoSmsController@bulkSend');
    $router->get('plivo/sms',                     'PlivoSmsController@list');

    // SIP Trunks (Applications)
    $router->get('plivo/trunks',                  'PlivoTrunkController@list');
    $router->post('plivo/trunks',                 'PlivoTrunkController@create');
    $router->put('plivo/trunks/{appId}',          'PlivoTrunkController@update');
    $router->delete('plivo/trunks/{appId}',       'PlivoTrunkController@delete');
});

// Plivo Webhooks — signature validated, no JWT
$router->group(['middleware' => ['plivo.webhook']], function () use ($router) {
    $router->post('plivo/webhook/inbound-call',   'PlivoWebhookController@inboundCall');
    $router->post('plivo/webhook/inbound-sms',    'PlivoWebhookController@inboundSms');
    $router->post('plivo/webhook/call-status',    'PlivoWebhookController@callStatus');
    $router->post('plivo/webhook/sms-status',     'PlivoWebhookController@smsStatus');
});
