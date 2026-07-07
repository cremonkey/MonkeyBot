<?php defined('BASEPATH') OR exit('No direct script access allowed');

// API mode:
//  'display'  = TikTok for Developers app (developers.tiktok.com) — account
//               connect + video list only. NO comment endpoints exist here.
//  'business' = TikTok API for Business app (business-api.tiktok.com/portal)
//               — required for comment auto-reply. Needs the Business Account
//               (Comment Management) products approved and a TikTok Business
//               account.
$config['tiktok_api_mode'] = getenv('TIKTOK_API_MODE') ?: 'display';

// TikTok for Developers (Login Kit / Display API) credentials.
$config['tiktok_client_key']    = getenv('TIKTOK_CLIENT_KEY') ?: '';
$config['tiktok_client_secret'] = getenv('TIKTOK_CLIENT_SECRET') ?: '';

// TikTok API for Business app credentials (App ID + Secret from
// business-api.tiktok.com/portal "My Apps").
$config['tiktok_business_app_id'] = getenv('TIKTOK_BUSINESS_APP_ID') ?: '';
$config['tiktok_business_secret'] = getenv('TIKTOK_BUSINESS_SECRET') ?: '';

// Feature flags
$config['tiktok_dm_enabled']    = false;
