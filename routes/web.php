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

$router->get('/', function () use ($router) {
  return $router->app->version();
});
$router->get('receiver-fax', 'FaxController@receiverFax');
$router->post('receiver-fax', 'FaxController@receiverFax');

//login
$router->POST('authentication', 'AuthenticationController@authentication');
$router->POST('authentication_copy', 'AuthenticationController@authentication_copy');

$router->POST('verify_google_otp', 'TwoFactorController@verify_google_otp');
//$router->POST('authentication_copy', 'AuthenticationController@authentication_copy');
$router->get('auth/google/redirect', 'GoogleController@redirectToGoogle');
$router->post('auth/google/callback', 'GoogleController@handleGoogleCallback');
$router->post('auth/twitter/callback', 'TwitterController@handleTwitterCallback');

//cron job
$router->get('add-lead-temp', 'CronController@addLeadTemp');
$router->get('cron-email', 'CronController@cronEmail');


//pusher
$router->post('check-and-get-user-id-for-pusher', 'PusherController@checkAndGetUserIdForPusher');

#Routes with super admin rights should be added here
$router->group(['middleware' => ['jwt.auth', 'auth.superadmin']], function () use ($router) {
  #create client
  $router->put('client', 'ClientController@create');
  $router->get('clients', 'ClientController@index');
  $router->get('client/{id}', 'ClientController@show');
  $router->post('client/manual-subscription', 'ClientController@performManualSubscription');
  Route::post('client/credit-wallet', 'ClientController@creditWallet');
  $router->post('client/{id}', 'ClientController@update');

  //sms providers

  $router->put('sms-provider/{id}', 'ClientController@createSmsProvider');

  $router->get('sms-provider/{id}', 'ClientController@showSmsProvider');

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
$router->group(['middleware' => ['jwt.auth', 'auth.admin']], function () use ($router) {
  #create user
  $router->put('user', 'ExtensionController@saveNewExtension');

  #User permissions
  $router->get('user/{userId}/permission', 'UserController@showPermission');
  $router->put('user/{userId}/permission', 'UserController@addPermission');
  $router->post('user/{userId}/permission', 'UserController@updatePermission');
  $router->delete('user/{userId}/permission', 'UserController@removePermission');
  $router->post('user/{userId}/assignable-roles', 'UserController@assignableRoles');
  $router->post('user/assignable-roles', 'UserController@assignableRolesNew');

  $router->post('user/{userId}/super-admin-permission', 'UserController@updatePermissionSuperAdmin');
  $router->get('user/{userId}/user-permission', 'UserController@userPermission');


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
  $router->delete('extension-group/{id}', 'GroupController@delete');
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

$router->group(['middleware' => 'jwt.auth'], function () use ($router) {
  // $router->post('auth/google/callback', 'UserMailController@googlecallback');
  //profile
  $router->get('profile', 'ProfileController@index');
  $router->post('/profile/update-two-factor', 'ProfileController@updateTwoFactor');
  $router->post('/profile/update-google-auth', 'ProfileController@updateGoogleAuthenticator');
  $router->post('verify-google_otp', 'ProfileController@verifyGoogleAuthenticator');
  //Extension-Group Read Operations
  $router->get('extension-group', 'GroupController@list');
  $router->get('extension-group/{id}', 'GroupController@show');
  $router->get('extension-group-map', 'ExtensionGroupMapController@index');


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
  $router->get('daily-call-report/{logId}', 'ReportController@getDailyCallReportView');
  $router->get('daily-call-report', 'ReportController@getDailyCallReportLogs');

  $router->post('live-call', 'ReportController@getLiveCall');
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

//campaign assign list

  $router->post('/campaign/assign-lists', 'CampaignController@assignLists');




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
  $router->post('edit-list', 'ListsController@editList');  //done for delete not edit
  $router->post('add-list', 'ListsController@addList');
  $router->post('add-list-api',  'ListsController@addListUsingApi');//done
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

  #Email
  $router->post('send-email/generic', 'MailController@sendGenericEmail');
  $router->post('send-email/generic-attachment', 'MailController@sendEmailGenericAttachment');



  $router->post('send-email/generics', 'MailController@sendGenericEmailCRM');
  $router->post('send-email/test-low-lead', 'MailController@testLowLead');

  #Notifications
  $router->get("notifications", "NotificationController@index");
  $router->post("notifications", "NotificationController@saveSubscriptions");

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
  //$router->post('warm-call-transfer', 'DialerController@warmCallTransfer');
  $router->post('warm-call-transfer-c2c-crm', 'DialerController@warmCallTransfer');
  $router->post('merge-call-with-transfer', 'DialerController@mergeCallWithTransfer');


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

  //crm lead status
  $router->get('leadStatus', 'LeadStatusController@list');
  $router->put('add-lead-status', 'LeadStatusController@create');
  $router->post('update-lead-status/{id}', 'LeadStatusController@update');
  $router->post('change-lead-status', 'LeadStatusController@changeStatus');
  $router->get('delete-lead-status/{id}', 'LeadStatusController@delete');
  $router->post('/lead-status/updateDisplayOrder', 'LeadStatusController@updateDisplayOrder');
  $router->post('change-view-on-dashboard-status', 'LeadStatusController@changeViewOnLead');
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


  $router->get('users', 'ExtensionController@getExtensionListCRM');
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

  /* Close Contact CRM */


  #lender label setting apis

  $router->get('lender-label-api-setting', 'LenderApiLabelController@index');
  $router->post('lender-label-api-setting', 'LenderApiLabelController@save');


  #pdf reader
  $router->get('pdf-reader-setting', 'PdfReaderController@index');
  $router->post('update-pdf-reader', 'PdfReaderController@update');
  $router->post('upload-pdf-reader', 'PdfReaderController@upload');
  //schedule
  $router->get('schedule', 'ScheduleController@index');
  $router->post('save-schedule', 'ScheduleController@store');
  $router->post('schedule/delete-schedule', 'ScheduleController@deleteSchedule');
});


//phone charges deduction
$router->post('call-billing', "CallBillingController@prepareBill");

//sms api receiveing from external
$router->post('receive-sms', 'SmsController@smsResponse');

$router->group(['middleware' => ['websiteclient']], function () use ($router) {
  $router->get('otp/email', 'OtpController@requestEmailOtp');
  $router->get('otp/phone', 'OtpController@requestPhoneOtp');
  $router->post('otp/verify', 'OtpController@verifyOtp');
});
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
$router->post('inbound-call-popup', "InboundCallPopUpController@inboundCallPopup");

//forgot password
$router->get('forgot-password-email/email', 'OtpController@requestForgotPasswordEmail');
$router->get('check-forgot-password-link/{token}', 'UserController@checkForgotPasswordLink');
$router->post('reset-password', 'UserController@resetPassword');

$router->post('forgot-password', 'UserController@forgotPassword');
$router->get('verify-token/{token}', 'UserController@verifyResetToken');
$router->post('resetPasswordUser', 'UserController@resetPasswordUser');
$router->post('forgot-password-mobile', 'UserController@forgotPasswordMobile');
$router->post('verify-token-mobile/{otp_id}', 'UserController@verifyResetTokenMobile');
$router->post('resetPasswordUserMobile', 'UserController@resetPasswordUserMobile');
$router->post('forgot-password-resend', 'UserController@forgotPasswordMobileResend');


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
