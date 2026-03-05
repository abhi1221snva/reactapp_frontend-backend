# Frontend API Integration Document
## Dialer CRM Phone System — Backend v2

**Document Version:** 1.0
**Backend Framework:** Lumen (Laravel Micro-framework)
**Base URL:** `https://<your-domain>/` *(no `/api` prefix)*
**Auth:** JWT Bearer Token
**Real-time:** Pusher WebSockets
**Date Generated:** 2026-03-05

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Authentication System](#2-authentication-system)
3. [Complete API Endpoint List](#3-complete-api-endpoint-list)
4. [Feature Modules](#4-feature-modules)
5. [Frontend Usage Flow](#5-frontend-usage-flow)
6. [Recommended Frontend Architecture](#6-recommended-frontend-architecture)
7. [UI Pages Required](#7-ui-pages-required)
8. [Frontend Folder Structure Recommendation](#8-frontend-folder-structure-recommendation)
9. [Webphone Integration](#9-webphone-integration)
10. [Security Recommendations](#10-security-recommendations)

---

## 1. Project Overview

This backend is a **full-featured Dialer CRM Phone System** built on the Lumen framework. It is a multi-tenant SaaS platform that manages:

| Domain | What it Does |
|--------|-------------|
| **Predictive / Power Dialing** | Automatically dials leads from lists and connects agents when answered |
| **Inbound Call Handling** | Receives inbound calls, routes via IVR, ring groups, and pop-ups to agents |
| **CRM (Contact Management)** | Manages leads, contacts, documents, labels, and lender pipelines |
| **Campaign Management** | Create and manage outbound dialing campaigns with lists and dispositions |
| **Agent / Extension Management** | Multi-role user system (SuperAdmin → Admin → Manager → Agent) with SIP extensions |
| **SMS Center** | Inbound/Outbound SMS per DID number with AI-driven auto-response |
| **IVR / Ring Groups / DID Management** | Full telephony routing configuration |
| **Reports & CDR** | Call Detail Records, agent performance, disposition summaries, live call monitoring |
| **Ringless Voicemail (RVM)** | Deliver voice messages directly to voicemail without ringing |
| **SMS AI Campaigns** | AI-driven outbound SMS campaigns with natural language responses |
| **Team Chat** | Internal messaging with real-time presence and WebRTC calling |
| **Gmail Integration** | OAuth-based Gmail mailbox integration with AI email analysis |
| **Attendance Management** | Agent clock-in/out, break tracking, shift management, attendance reports |
| **Billing & Wallet** | Stripe payment methods, wallet balance, package subscriptions |

### Architecture Notes for Frontend
- **Multi-tenant:** Every user belongs to a `parent_id` (client/account). All data queries are scoped per client.
- **Role Hierarchy:** `SuperAdmin (level 10)` > `Admin (level 7+)` > `Manager (level 5–6)` > `Agent (level < 5)`
- **SIP/Asterisk integration:** Call operations hit the Asterisk PBX server via AMI (Asterisk Manager Interface). The server hostname and domain are returned on login.
- **Real-time Events:** Pusher is used for call ringing notifications, SMS notifications, and presence updates.
- **Redis Caching:** Dashboard metrics and frequently accessed data are cached in Redis.

---

## 2. Authentication System

### 2.1 Login Flow Overview

```
POST /authentication
     ↓
Returns JWT token + user profile + SIP credentials + server info
     ↓
Store: { token, extension, alt_extension, server, domain, secret }
     ↓
All subsequent requests: Authorization: Bearer <token>
```

### 2.2 Primary Login Endpoint

**POST** `/authentication`

> This is the main login for the web/desktop app.

#### Request Headers
```http
Content-Type: application/json
```

#### Request Body
```json
{
  "email": "agent@company.com",
  "password": "yourpassword",
  "device": "desktop_app",
  "clientIp": "192.168.1.1"
}
```

| Field | Required | Values | Description |
|-------|----------|--------|-------------|
| `email` | Yes | string | User email |
| `password` | Yes | string | User password |
| `device` | Yes | `desktop_app` / `mobile_app` | Device type |
| `clientIp` | No | IP string | Client's IP (used for IP filtering). Falls back to server-detected IP |
| `apiKey` | No | string | Alternative: login with API key instead of password |

#### Success Response (200)
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "id": 42,
    "parent_id": 5,
    "base_parent_id": 5,
    "first_name": "John",
    "last_name": "Doe",
    "email": "agent@company.com",
    "mobile": "5551234567",
    "country_code": "+1",
    "extension": "1001",
    "alt_extension": "91001",
    "app_extension": "81001",
    "dialer_mode": "webphone",
    "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "expires_at": "2026-03-06T10:00:00Z",
    "server": "sip.yourserver.com",
    "domain": "yourserver.com",
    "did": "15551234567",
    "secret": "encoded_sip_password",
    "webphone": 0,
    "companyName": "ACME Corp",
    "companyLogo": "https://cdn.example.com/logo.png",
    "profile_pic": null,
    "ip_filtering": 0,
    "enable_2fa": 0,
    "permissions": { "5": { "companyName": "...", "role": "admin" } }
  }
}
```

#### 2FA Flow (when `enable_2fa: 1` or `is_2fa_phone_enabled: 1`)
If 2FA is enabled, the login response will include `otpId` and the OTP is sent via SMS/email. Frontend must then call:

**POST** `/verify_google_otp`
```json
{ "otp": "123456", "otpId": "uuid-from-login-response" }
```

#### Error Responses
| HTTP | Message |
|------|---------|
| 401 | `"Invalid email or password"` |
| 401 | `"Account de-activated"` |
| 401 | `"Unauthorised IP address"` |
| 401 | `"Unauthorised For Mobile App Access"` |

---

### 2.3 V2 Login (Easify Platform)

**POST** `/v2/login`

Used by the Easify white-label platform. Requires special headers.

#### Request Headers
```http
X-Easify-App-Key: <APP_KEY>
X-Easify-User-Token: <EASIFY_USER_UUID>
```

---

### 2.4 Token Usage

After login, every protected API call requires:

```http
Authorization: Bearer <token>
Content-Type: application/json
```

The token is a **JWT (HS256)** signed with the server's `JWT_SECRET`. It contains:
- `sub`: user ID
- `iat`: issued at
- `exp`: expiration

When the token expires, redirect user to login. No refresh token mechanism is documented — re-login required.

---

### 2.5 Roles and Permission Levels

| Level | Role | Access |
|-------|------|--------|
| 10 | SuperAdmin | Full platform access, cross-client management |
| 7–9 | Admin | Full access within their client account |
| 5–6 | Manager | Can monitor agents, view reports |
| 1–4 | Agent | Dialer operations only |

Permission checks are done server-side. The `user.level` field in the JWT payload determines what the user can access. Frontend should use this to show/hide UI elements.

---

### 2.6 Password Reset Flow

```
1. POST /forgot-password  { email }
2. User receives email with reset link
3. GET  /verify-token/{token}         → validates token
4. POST /resetPasswordUser            { token, password, password_confirmation }
```

Or via mobile OTP:
```
1. POST /forgot-password-mobile       { mobile, country_code }
2. POST /verify-token-mobile/{otp_id} { otp }
3. POST /resetPasswordUserMobile      { otp_id, password }
```

---

## 3. Complete API Endpoint List

> **Legend:**
> - 🔐 = Requires `Authorization: Bearer <token>`
> - 🔓 = Public endpoint
> - 📄 = Pagination supported (`start`, `limit` params)

---

### 3.1 Authentication

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/authentication` | 🔓 | Primary login |
| POST | `/authentication_copy` | 🔓 | Legacy login copy |
| POST | `/verify_google_otp` | 🔓 | Verify 2FA OTP |
| GET | `/auth/google/redirect` | 🔓 | Google OAuth redirect |
| POST | `/auth/google/callback` | 🔓 | Google OAuth callback |
| POST | `/v2/login` | 🔓 | Easify platform login |
| POST | `/v2/register` | 🔓 | Register new user (Easify) |
| POST | `/v2/validate-email` | 🔓 | Check if email exists |
| POST | `/forgot-password` | 🔓 | Send forgot password email |
| GET | `/verify-token/{token}` | 🔓 | Verify reset token |
| POST | `/resetPasswordUser` | 🔓 | Reset password |
| POST | `/forgot-password-mobile` | 🔓 | Send OTP to mobile |
| POST | `/verify-token-mobile/{otp_id}` | 🔓 | Verify mobile OTP |
| POST | `/resetPasswordUserMobile` | 🔓 | Reset password via mobile |

---

### 3.2 Dashboard

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/dashboard` | 🔐 | Main dashboard summary |
| POST | `/dashboard/revenue-metrics` | 🔐 | Revenue metrics |
| POST | `/dashboard-state` | 🔐 | Save dashboard layout/state |
| GET | `/dashboard-state` | 🔐 | Get saved dashboard state |
| GET | `/count-dids` | 🔐 | Count of DIDs |
| POST | `/did-count` | 🔐 | DID count with filters |
| POST | `/user-count` | 🔐 | Agent/extension count |
| POST | `/campaigns-count` | 🔐 | Campaign count |
| POST | `/lead-count` | 🔐 | Lead count |
| POST | `/cdr-call-count` | 🔐 | CDR call count |
| POST | `/cdr-call-agent-count` | 🔐 | CDR call count per agent |
| POST | `/cdr-count-range` | 🔐 | CDR calls by date range |
| POST | `/disposition-wise-call` | 🔐 | Calls grouped by disposition |
| POST | `/state-wise-call` | 🔐 | Calls grouped by state |
| POST | `/cdr-dashboard-summary` | 🔐 | Full CDR dashboard summary |
| POST | `/voicemail-count` | 🔐 | Voicemail count |
| POST | `/voicemail-unread` | 🔐 | Unread voicemail count |
| POST | `/extension-summary` | 🔐 | Per-extension call summary |
| POST | `/inbound-count-avg` | 🔐 | Inbound call count and average |
| POST | `/dialer-all-count` | 🔐 | Dialer-wide count metrics |
| POST | `/dialer-all-count-crm` | 🔐 | CRM dialer count metrics |

#### POST `/dashboard` — Request/Response

**Request:**
```json
{
  "startTime": "2026-01-01 00:00:00",
  "endTime": "2026-03-05 23:59:59"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Dashboard Summary",
  "data": {
    "totalCallbacks": 42,
    "totalUsers": 10,
    "totalCampaigns": 5,
    "totalDids": 7,
    "totalLeads": 2000,
    "totalList": 15,
    "incomingSms": 120,
    "outgoingSms": 95,
    "unreadVoicemail": 8,
    "receivedVoicemail": 20
  }
}
```

---

### 3.3 Users / Agents (Extensions)

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/extension` | 🔐 | List all extensions 📄 |
| GET | `/extension/{id}` | 🔐 | Get extension by ID |
| POST | `/extension` | 🔐 | Get extension(s) with filter |
| POST | `/extension-list` | 🔐 | Paginated extension list |
| POST | `/add-extension` | 🔐 | Create new agent/extension |
| POST | `/edit-extension` | 🔐 | Get extension data for edit |
| POST | `/edit-extension-save` | 🔐 | Save edited extension |
| POST | `/check-extension` | 🔐 | Check if extension is available |
| POST | `/check-alt-extension` | 🔐 | Check alternate extension |
| POST | `/check-extension-live` | 🔐 | Check if extension is online |
| GET | `/role` | 🔐 | List available roles |
| GET | `/users` | 🔐 | CRM users list |
| GET | `/users-list-new` | 🔐 | New CRM users list |
| GET | `/user/{id}` | 🔐 | Get user by ID |
| POST | `/user-detail` | 🔐 | Get user detail for current session |
| POST | `/update-profile` | 🔐 | Update user profile |
| POST | `/update-user-password` | 🔐 | Change own password |
| POST | `/update-agent-password-by-admin` | 🔐 | Admin changes agent password |
| POST | `/change-dialer-mode-extension` | 🔐 | Switch between webphone/physical phone |
| POST | `/update-allowed-ip` | 🔐 | Update allowed IP whitelist |
| POST | `/user-setting` | 🔐 | Save user preferences |
| POST | `/update-timezone` | 🔐 | Update user timezone |
| POST | `/update-logo` | 🔐 | Upload company logo |
| POST | `/user-menu` | 🔐 | Get menu items for user |
| GET | `/profile` | 🔐 | Get own profile |
| POST | `/switch-client/{clientId}` | 🔐 | Switch to another client account |
| GET | `/user/{userId}/permission` | 🔐 | Get user permissions |
| PUT | `/user/{userId}/permission` | 🔐 | Add user permission |
| POST | `/user/{userId}/permission` | 🔐 | Update user permission |
| DELETE | `/user/{userId}/permission` | 🔐 | Remove user permission |
| PUT | `/user` | 🔐 | Create new extension (admin) |
| POST | `/new-extension-save` | 🔐 | Save new extension |

#### POST `/add-extension` — Request
```json
{
  "first_name": "Jane",
  "last_name": "Smith",
  "email": "jane@company.com",
  "extension": "1002",
  "password": "secure123",
  "role": "agent",
  "did": "15551234567",
  "dialer_mode": "webphone"
}
```

#### POST `/check-extension-live` — Response
```json
{
  "success": true,
  "message": "Extension live status",
  "data": [
    {
      "extension": "1001",
      "status": "LOGGED_IN",
      "campaign_id": 5,
      "in_call": true
    }
  ]
}
```

---

### 3.4 Campaign Management

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/campaigns` | 🔐 | List all campaigns |
| GET | `/campaign-type` | 🔐 | Get campaign types |
| POST | `/campaign` | 🔐 | Paginated/filtered campaign list |
| POST | `/add-campaign` | 🔐 | Create campaign |
| POST | `/edit-campaign` | 🔐 | Update campaign |
| POST | `/delete-campaign` | 🔐 | Delete campaign |
| POST | `/copy-campaign` | 🔐 | Duplicate campaign |
| POST | `/campaign-by-id` | 🔐 | Get campaign by ID |
| POST | `/status-update-campaign` | 🔐 | Toggle campaign status |
| POST | `/status-update-hopper` | 🔐 | Toggle campaign hopper |
| POST | `/campaign-list` | 🔐 | Campaign with its assigned lists |
| POST | `/list-disposition` | 🔐 | Campaign dispositions + lists |
| POST | `/delete-list-disposition` | 🔐 | Remove disposition from campaign |
| POST | `/campaign/{id}/schedule` | 🔐 | Get call schedule for campaign |
| POST | `/campaign/assign-lists` | 🔐 | Assign lists to campaign |

#### POST `/add-campaign` — Request
```json
{
  "name": "Q1 Sales Push",
  "campaign_type_id": 1,
  "group_id": 3,
  "call_time_start": "09:00:00",
  "call_time_end": "18:00:00",
  "timezone": "America/New_York",
  "dial_ratio": 3,
  "dial_method": "RATIO",
  "did_id": 7,
  "status": 1,
  "list_ids": [10, 11, 12]
}
```

**Response:**
```json
{
  "success": true,
  "message": "Campaign added successfully.",
  "data": { "id": 25 }
}
```

---

### 3.5 Dialer Operations

> These are the core real-time call control APIs.

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/extension-login` | 🔐 | Log agent into dialer with campaign |
| POST | `/user-logout` | 🔐 | Log agent out of dialer |
| POST | `/agent-campaign` | 🔐 | Get campaigns available for agent |
| POST | `/lead-temp` | 🔐 | Get lead count in temp (hopper) table |
| POST | `/call-number` | 🔐 | Click-to-call a number |
| POST | `/hang-up` | 🔐 | Hang up active call |
| POST | `/get-lead` | 🔐 | Fetch next assigned lead |
| GET | `/get-lead` | 🔐 | Fetch next lead (GET variant) |
| POST | `/save-disposition` | 🔐 | Save call disposition after hang-up |
| POST | `/disposition-campaign` | 🔐 | Get dispositions for a campaign |
| POST | `/disposition_by_campaignId` | 🔐 | Get dispositions by campaign ID |
| POST | `/redial-call` | 🔐 | Redial a number |
| POST | `/dtmf` | 🔐 | Send DTMF tone during call |
| POST | `/voicemail-drop` | 🔐 | Drop voicemail to current lead |
| POST | `/send-to-crm` | 🔐 | Send current lead data to CRM |
| POST | `/listen-call` | 🔐 | Supervisor listens to agent call |
| POST | `/barge-call` | 🔐 | Supervisor barges into agent call |
| POST | `/add-new-lead-pd` | 🔐 | Add new lead during predictive dialing |
| POST | `/click2call` | 🔐 | Outbound AI click-to-call |
| POST | `/direct-call-transfer` | 🔐 | Cold transfer call |
| POST | `/warm-call-transfer-c2c-crm` | 🔐 | Warm (attended) transfer |
| POST | `/merge-call-with-transfer` | 🔐 | Merge consultation call into transfer |
| POST | `/leave-conference-transfer` | 🔐 | Leave conference after transfer |
| POST | `/check-line-details` | 🔐 | Check active line details |
| POST | `/check-extension-live-for-transfer` | 🔐 | Check if target extension is available for transfer |
| POST | `/webphone/switch-access` | 🔐 | Toggle webphone on/off |
| GET | `/webphone/status` | 🔐 | Get current webphone status |
| GET | `/asterisk-login` | 🔐 | CRM webphone login / click-to-call |
| GET | `/asterisk-hang-up` | 🔐 | CRM webphone hang-up |
| POST | `/update-lead/{leadId}` | 🔐 | Update lead data during dialing session |
| POST | `/view-notes/{leadId}` | 🔐 | View notes for a lead |

#### POST `/extension-login` — Request/Response

**Request:**
```json
{ "campaign_id": 25 }
```

**Response (200 — Success):**
```json
{
  "success": true,
  "message": "You are logged in successfully. Lead assigned.",
  "data": {
    "lead_id": 1042,
    "number": "5551234567",
    "first_name": "Alice",
    "last_name": "Brown"
  }
}
```

**Response (402 — Failure):**
```json
{
  "success": false,
  "message": "You do not have dialing permission"
}
```

---

#### POST `/call-number` — Request/Response

**Request:**
```json
{
  "campaign_id": 25,
  "lead_id": 1042,
  "number": "5551234567",
  "id": 1
}
```

**Response:**
```json
{
  "success": "true",
  "message": "Number called"
}
```

---

#### POST `/save-disposition` — Request

```json
{
  "campaign_id": 25,
  "lead_id": 1042,
  "disposition_id": 3,
  "callback_date": "2026-03-10",
  "callback_time": "14:00:00",
  "notes": "Customer interested, call back next week"
}
```

---

#### POST `/agent-campaign` — Response

```json
{
  "success": true,
  "message": "List of campaign for extension.",
  "utc_time": "2026-03-05 10:00:00",
  "timezone": "America/New_York",
  "timezoneValue": "-05:00",
  "time": "05:00:00(-05:00)",
  "data": [
    {
      "id": 25,
      "name": "Q1 Sales Push",
      "group_id": 3,
      "call_time_start": "09:00:00",
      "call-time_end": "18:00:00",
      "status": 1
    }
  ],
  "login": {
    "extension": "1001",
    "status": "Logged In"
  }
}
```

---

### 3.6 Leads / Lists (Dialer-side)

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/list` | 🔐 | Get all lists 📄 |
| POST | `/raw-list` | 🔐 | Lists without campaign association |
| POST | `/add-list` | 🔐 | Create list |
| POST | `/edit-list` | 🔐 | Edit/delete list |
| POST | `/status-update-list` | 🔐 | Toggle list status |
| POST | `/add-list-api` | 🔐 | Create list via API |
| GET | `/list/{id}/content` | 🔐 | Get list content (leads) |
| POST | `/list-data/{id}/content` | 🔐 | Get list leads with filters |
| POST | `/list-header` | 🔐 | Get list column headers |
| POST | `/search-leads` | 🔐 | Search across leads |
| POST | `/get-data-for-edit-lead-page` | 🔐 | Prefill lead edit form |
| POST | `/update-lead-data` | 🔐 | Update lead row data |
| POST | `/change-disposition` | 🔐 | Change lead disposition |
| GET | `/count-lists` | 🔐 | Count total lists |

---

### 3.7 CRM Contacts / Leads

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/leads` | 🔐 | List CRM leads with filters 📄 |
| POST | `/lead-new` | 🔐 | New leads listing |
| PUT | `/lead/add` | 🔐 | Create new CRM lead |
| PUT | `/lead/addLead` | 🔐 | Alternative create lead |
| GET | `/lead/{id}` | 🔐 | Get single lead |
| POST | `/lead/{id}/edit` | 🔐 | Update lead |
| GET | `/lead/{id}/delete` | 🔐 | Delete lead |
| POST | `/lead/{id}/view` | 🔐 | View lead with activity log |
| POST | `/lead/import` | 🔐 | Bulk import leads (CSV) |
| POST | `/lead-status/{id}/edit` | 🔐 | Update lead status |
| GET | `/send-data-on-webhook/{id}` | 🔐 | Send lead data to webhook |
| POST | `/leads/add/opener` | 🔐 | Assign opener agent to lead |
| POST | `/leads/add/closer` | 🔐 | Assign closer agent to lead |
| PUT | `/lead/copy` | 🔐 | Copy a lead |
| POST | `/leadTask/add` | 🔐 | Add scheduled task to lead |
| GET | `/crm-scheduled-task/{lead_id}` | 🔐 | Get lead's scheduled tasks |
| GET | `/crm-scheduled-task/{lead_id}/{id}/delete` | 🔐 | Delete a task |
| GET | `/leadStatus` | 🔐 | List lead statuses |
| PUT | `/add-lead-status` | 🔐 | Create lead status |
| POST | `/update-lead-status/{id}` | 🔐 | Update lead status |
| GET | `/delete-lead-status/{id}` | 🔐 | Delete lead status |
| GET | `/crm-labels` | 🔐 | List CRM labels |
| PUT | `/crm-add-label` | 🔐 | Create CRM label |
| POST | `/crm-update-label/{id}` | 🔐 | Update CRM label |
| GET | `/crm-delete-label/{id}` | 🔐 | Delete CRM label |
| GET | `/lenders` | 🔐 | List lenders |
| GET | `/lender/{id}` | 🔐 | Get lender |
| PUT | `/lender` | 🔐 | Create lender |
| POST | `/lender/{id}` | 🔐 | Update lender |
| DELETE | `/delete-lender/{id}` | 🔐 | Delete lender |
| GET | `/documents` | 🔐 | List documents |
| GET | `/documents/{lead_id}` | 🔐 | Documents for a lead |
| PUT | `/document` | 🔐 | Upload document |
| POST | `/update-document/{id}` | 🔐 | Update document |
| GET | `/delete-document/{id}` | 🔐 | Delete document |
| GET | `/document-types` | 🔐 | List document types |
| GET | `/lead-source` | 🔐 | List lead sources |
| PUT | `/add-lead-source` | 🔐 | Create lead source |
| GET | `/send-lead-to-lenders/{id}` | 🔐 | Get list of lenders for lead |
| GET | `/lender-status` | 🔐 | Lender submission statuses |
| PUT | `/lender/notes/add` | 🔐 | Add note to lender |
| GET | `/showlenders/{id}` | 🔐 | View lender notes |
| GET | `/notifications-crm` | 🔐 | CRM notifications |
| PUT | `/notification-crm/add` | 🔐 | Add CRM notification |

---

### 3.8 SMS

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET/POST | `/sms` | 🔐 | Get SMS list |
| GET/POST | `/sms-by-did` | 🔐 | SMS conversation by DID |
| GET/POST | `/sms-by-did-recent` | 🔐 | Recent SMS by DID |
| GET | `/sms_did_list` | 🔐 | DIDs with SMS capability |
| GET | `/sms-did-list-crm` | 🔐 | CRM SMS DID list |
| POST | `/send-sms` | 🔐 | Send SMS |
| POST | `/sms-count` | 🔐 | SMS count by date range |
| GET/POST | `/unread-sms-count` | 🔐 | Unread SMS count |
| POST | `/receive-sms` | 🔓 | Webhook: receive inbound SMS |

#### POST `/send-sms` — Request
```json
{
  "to": "5551234567",
  "from": "15551234567",
  "message": "Hello, this is a follow-up from our team.",
  "lead_id": 1042
}
```

#### GET/POST `/sms-by-did` — Response
```json
{
  "success": true,
  "message": "SMS list",
  "data": [
    {
      "id": 101,
      "from": "+15551234567",
      "to": "+15559876543",
      "body": "Hi, are you available?",
      "type": "outgoing",
      "read": 1,
      "created_at": "2026-03-05 09:30:00"
    }
  ]
}
```

---

### 3.9 Reports & CDR

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/report` | 🔐 | Full CDR report with filters |
| POST | `/get-cdr` | 🔐 | Raw CDR data |
| POST | `/report-lead-id` | 🔐 | CDR by lead ID |
| POST | `/daily-call-report` | 🔐 | Generate daily call report |
| GET | `/daily-call-report` | 🔐 | List daily call report logs |
| GET | `/daily-call-report/{logId}` | 🔐 | View specific daily call report |
| POST | `/live-call` | 🔐 | Live active calls |
| POST | `/transfer-report` | 🔐 | Call transfer report |
| POST | `/login-history` | 🔐 | Agent login history |
| GET | `/get-timezone-list` | 🔐 | List of timezones |
| POST | `/active-extension-group-list` | 🔐 | Active extensions by group |
| POST | `/extension-group-list` | 🔐 | All extensions by group |
| POST | `/call-matrix-report` | 🔐 | Store call matrix analysis request |
| POST | `/call-matrix/process` | 🔐 | Process call matrix |
| GET | `/call-matrix-report/{reference_id}` | 🔐 | View call matrix results |
| POST | `/report-press1-campaign` | 🔐 | Press-1 (IVR) campaign report |
| POST | `/all-dtmf` | 🔐 | All DTMF events |
| POST | `/ivr-logs` | 🔐 | IVR log entries |
| GET | `/cli-report/fetch_data` | 🔐 | CLI report data |

#### POST `/report` — Request Parameters
```json
{
  "number": "5551234567",
  "numbers": ["5551234567", "5559876543"],
  "area_code": ["212", "646"],
  "timezone_value": "America/New_York",
  "campaign": 25,
  "disposition": [3, 5],
  "type": "outgoing",
  "start_date": "2026-01-01",
  "end_date": "2026-03-05",
  "extension": "1001",
  "did_numbers": ["15551234567"],
  "lower_limit": 0,
  "upper_limit": 100
}
```

---

### 3.10 DID Management

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/did` | 🔐 | List all DIDs |
| POST | `/did` | 🔐 | Paginated/filtered DID list |
| POST | `/add-did` | 🔐 | Create/add DID |
| POST | `/edit-did` | 🔐 | Get DID for edit |
| POST | `/save-edit-did` | 🔐 | Save edited DID |
| POST | `/delete-did` | 🔐 | Delete DID |
| POST | `/did_detail` | 🔐 | DID detail |
| POST | `/get-call-timings` | 🔐 | Get call schedule for DID |
| POST | `/save-call-timings` | 🔐 | Save call timing schedule |
| POST | `/get-all-holidays` | 🔐 | Get holiday schedule |
| POST | `/save-holiday-detail` | 🔐 | Save holiday |
| POST | `/get-department-list` | 🔐 | Departments for DID routing |
| POST | `/get-did-list-from-sale` | 🔐 | Available DIDs from DID provider |
| POST | `/buy-save-selected-did` | 🔐 | Purchase and save DID |
| POST | `/buy-save-selected-did-plivo` | 🔐 | Purchase DID via Plivo |
| POST | `/buy-save-selected-did-telnyx` | 🔐 | Purchase DID via Telnyx |
| POST | `/buy-save-selected-did-twilio` | 🔐 | Purchase DID via Twilio |
| POST | `/upload-did` | 🔐 | Bulk upload DIDs |
| POST | `/fax-did` | 🔐 | Fax-capable DIDs |
| POST | `/employee-directory` | 🔐 | Employee/extension directory |

---

### 3.11 IVR (Interactive Voice Response)

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/ivr` | 🔐 | List IVR trees |
| POST | `/add-ivr` | 🔐 | Create IVR |
| POST | `/edit-ivr` | 🔐 | Update IVR |
| POST | `/delete-ivr` | 🔐 | Delete IVR |
| POST | `/ivr-menu` | 🔐 | List IVR menu items |
| POST | `/add-ivr-menu` | 🔐 | Add IVR menu item |
| POST | `/edit-ivr-menu` | 🔐 | Update IVR menu item |
| POST | `/delete-ivr-menu` | 🔐 | Delete IVR menu item |
| POST | `/dest-type` | 🔐 | Destination types (for IVR routing) |
| GET | `/audio-message` | 🔐 | List audio messages |
| POST | `/add-audio-message` | 🔐 | Upload audio message |
| POST | `/edit-audio-message` | 🔐 | Update audio message |

---

### 3.12 Ring Groups

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/ring-group` | 🔐 | List ring groups |
| POST | `/add-ring-group` | 🔐 | Create ring group |
| POST | `/edit-ring-group` | 🔐 | Update ring group |
| POST | `/delete-ring-group` | 🔐 | Delete ring group |
| GET | `/extension-ring-group` | 🔐 | Extensions mapped to ring groups |

---

### 3.13 Extension Groups

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/extension-group` | 🔐 | List extension groups 📄 |
| GET | `/extension-group/{id}` | 🔐 | Get group by ID |
| PUT | `/extension-group` | 🔐 | Create extension group |
| PATCH | `/extension-group/{id}` | 🔐 | Update extension group |
| DELETE | `/extension-group/{id}` | 🔐 | Delete extension group |
| POST | `/extension/deleteFromGroup` | 🔐 | Remove extension from group |
| POST | `/status-update-group` | 🔐 | Toggle group status |

---

### 3.14 Dispositions

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/disposition` | 🔐 | List dispositions |
| POST | `/add-disposition` | 🔐 | Create disposition |
| POST | `/edit-disposition` | 🔐 | Update disposition |
| POST | `/status-update-disposition` | 🔐 | Toggle disposition status |
| POST | `/campaign-disposition` | 🔐 | Campaign-specific dispositions |
| POST | `/edit-campaign-disposition` | 🔐 | Edit campaign disposition |

---

### 3.15 DNC (Do Not Call)

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/dnc` | 🔐 | List DNC numbers |
| POST | `/add-dnc` | 🔐 | Add number to DNC |
| POST | `/edit-dnc` | 🔐 | Update DNC entry |
| POST | `/delete-dnc` | 🔐 | Remove from DNC |
| POST | `/upload-dnc` | 🔐 | Bulk upload DNC list |
| GET | `/dnc/fetch_data` | 🔐 | Fetch DNC data stream |

---

### 3.16 Callbacks

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/callback` | 🔐 | List scheduled callbacks |
| POST | `/callback/edit` | 🔐 | Update callback |
| GET | `/callback-reminder/stop` | 🔐 | Stop callback reminder |
| GET | `/callback-reminder/show` | 🔐 | Show callback reminder |
| GET | `/callback-reminder/status` | 🔐 | Reminder status |

---

### 3.17 Voicemail

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/add-voice-mail-drop` | 🔐 | Create voicemail drop |
| GET | `/view-voicemail` | 🔐 | View voicemail drops |
| POST | `/edit-voicemail` | 🔐 | Edit voicemail drop |
| POST | `/update-voiemail` | 🔐 | Update voicemail |
| POST | `/delete-voicemail` | 🔐 | Delete voicemail |
| POST | `/send-vm-to-email` | 🔓 | Send voicemail to email |
| POST | `/mailbox` | 🔐 | Voicemail/mailbox list |
| POST | `/edit-mailbox` | 🔐 | Update mailbox entry |
| POST | `/delete-mailbox` | 🔐 | Delete mailbox entry |
| POST | `/unread-mailbox` | 🔐 | Unread mailbox count |

---

### 3.18 Fax

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/fax` | 🔐 | List faxes |
| POST | `/fax/{id}` | 🔐 | Get fax PDF |
| POST | `/send-fax` | 🔐 | Send fax |
| POST | `/receive-fax-list` | 🔐 | Received faxes |
| POST | `/get-unread-fax-count` | 🔐 | Unread fax count |
| GET | `/receiver-fax` | 🔓 | Fax receiver webhook |

---

### 3.19 SMS AI

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/add-open-ai-setting` | 🔐 | Create AI setting |
| GET | `/open-ai-setting` | 🔐 | List AI settings |
| POST | `/update-open-ai-setting/{id}` | 🔐 | Update AI setting |
| POST | `/sms-ai-history` | 🔐 | SMS AI conversation history |
| POST | `/delete-message-ai` | 🔐 | Delete AI message |
| POST | `/unread-sms-count-openai` | 🔐 | Unread AI SMS count |
| GET | `/smsai/campaigns` | 🔐 | SMS AI campaigns |
| PUT | `/smsai/campaign/add` | 🔐 | Create SMS AI campaign |
| GET | `/smsai/campaign/view/{id}` | 🔐 | View SMS AI campaign |
| POST | `/smsai/campaign/update/{id}` | 🔐 | Update SMS AI campaign |
| POST | `/smsai/campaign/delete` | 🔐 | Delete SMS AI campaign |
| POST | `/smsai/reports` | 🔐 | SMS AI reports |

---

### 3.20 Ringless Voicemail (RVM)

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/ringless/campaign` | 🔐 | List RVM campaigns |
| POST | `/ringless/campaign/add` | 🔐 | Create RVM campaign |
| POST | `/ringless/campaign/edit` | 🔐 | Update RVM campaign |
| POST | `/ringless/campaign/delete` | 🔐 | Delete RVM campaign |
| POST | `/ringless/campaign/update-status` | 🔐 | Toggle RVM campaign |
| POST | `/ringless/reports/call-data` | 🔐 | RVM reports |
| GET | `/ringless/wallet/amount` | 🔐 | RVM wallet balance |
| GET | `/ringless/wallet/transactions` | 🔐 | RVM transactions |

---

### 3.21 Marketing Campaigns (Email/SMS Drip)

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/marketing-campaigns` | 🔐 | List marketing campaigns |
| PUT | `/marketing-campaign` | 🔐 | Create marketing campaign |
| GET | `/marketing-campaign/{id}` | 🔐 | View marketing campaign |
| POST | `/marketing-campaign/{id}` | 🔐 | Update marketing campaign |
| GET | `/marketing-campaigns-schedule/{id}` | 🔐 | Campaign schedules |
| PUT | `/marketing-campaign-schedule` | 🔐 | Create email schedule |
| PUT | `/marketing-campaign-schedule-sms` | 🔐 | Create SMS schedule |
| POST | `/delete-schedule` | 🔐 | Delete schedule |
| POST | `/abort-schedule` | 🔐 | Abort running schedule |
| GET | `/drip-campaigns` | 🔐 | Drip campaigns |
| PUT | `/drip-email-template` | 🔐 | Create drip campaign |

---

### 3.22 Team Chat

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/team-chat/conversations` | 🔐 | List all conversations |
| POST | `/team-chat/conversations` | 🔐 | Create conversation |
| POST | `/team-chat/conversations/direct` | 🔐 | Get or create direct message |
| GET | `/team-chat/conversations/{uuid}` | 🔐 | Get conversation |
| GET | `/team-chat/conversations/{uuid}/messages` | 🔐 | Get messages |
| POST | `/team-chat/conversations/{uuid}/messages` | 🔐 | Send message |
| POST | `/team-chat/conversations/{uuid}/attachments` | 🔐 | Upload attachment |
| POST | `/team-chat/conversations/{uuid}/read` | 🔐 | Mark as read |
| POST | `/team-chat/conversations/{uuid}/typing` | 🔐 | Send typing indicator |
| POST | `/team-chat/conversations/{uuid}/participants` | 🔐 | Add participants |
| POST | `/team-chat/conversations/{uuid}/leave` | 🔐 | Leave conversation |
| GET | `/team-chat/unread-count` | 🔐 | Total unread messages |
| POST | `/team-chat/presence` | 🔐 | Update presence status |
| GET | `/team-chat/users/online` | 🔐 | Get online users |
| GET | `/team-chat/users/search` | 🔐 | Search users |
| POST | `/team-chat/pusher/auth` | 🔐 | Authenticate Pusher channel |
| POST | `/team-chat/conversations/{uuid}/call` | 🔐 | Initiate WebRTC call |
| POST | `/team-chat/conversations/{uuid}/call/signal` | 🔐 | WebRTC signaling |
| POST | `/team-chat/conversations/{uuid}/call/accept` | 🔐 | Accept call |
| POST | `/team-chat/conversations/{uuid}/call/end` | 🔐 | End call |
| GET | `/team-chat/ice-servers` | 🔐 | Get ICE server list (STUN/TURN) |
| GET | `/team-chat/widget-data` | 🔐 | Widget configuration |

---

### 3.23 Gmail Integration

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/gmail/connect` | 🔐 | Start Gmail OAuth flow |
| POST | `/gmail/disconnect` | 🔐 | Disconnect Gmail |
| GET | `/gmail/status` | 🔐 | Gmail connection status |
| POST | `/gmail/refresh-token` | 🔐 | Refresh Gmail token |
| POST | `/gmail/watch/setup` | 🔐 | Setup Gmail push notifications |
| GET | `/gmail/mailbox` | 🔐 | List emails |
| GET | `/gmail/mailbox/labels` | 🔐 | Get Gmail labels |
| GET | `/gmail/mailbox/{messageId}` | 🔐 | Read single email |
| POST | `/gmail/mailbox/send` | 🔐 | Send email via Gmail |
| POST | `/gmail/mailbox/{messageId}/star` | 🔐 | Star message |
| POST | `/gmail/mailbox/{messageId}/trash` | 🔐 | Trash message |
| POST | `/gmail/mailbox/{messageId}/read` | 🔐 | Mark as read |
| GET | `/gmail/settings` | 🔐 | Gmail notification settings |
| POST | `/gmail/settings` | 🔐 | Update notification settings |
| GET | `/gmail/logs` | 🔐 | Gmail notification logs |
| POST | `/gmail/webhook` | 🔓 | Pub/Sub webhook (Google) |

---

### 3.24 Attendance Management

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/attendance/clock-in` | 🔐 | Clock in for the day |
| POST | `/attendance/clock-out` | 🔐 | Clock out |
| GET | `/attendance/status` | 🔐 | Current attendance status |
| POST | `/attendance/break/start` | 🔐 | Start break |
| POST | `/attendance/break/end` | 🔐 | End break |
| POST | `/attendance/list` | 🔐 | Attendance list (admin) |
| GET | `/attendance/my-attendance` | 🔐 | Own attendance history |
| POST | `/attendance/update` | 🔐 | Update attendance record (admin) |
| POST | `/attendance/report/daily` | 🔐 | Daily attendance report |
| POST | `/attendance/report/weekly` | 🔐 | Weekly attendance report |
| POST | `/attendance/report/monthly` | 🔐 | Monthly attendance report |
| POST | `/attendance/report/summary` | 🔐 | Summary attendance report |
| POST | `/attendance/report/alerts` | 🔐 | Late/early departure alerts |

#### POST `/attendance/clock-in` — Response
```json
{
  "success": "true",
  "message": "Clocked in successfully.",
  "is_late": false,
  "late_minutes": 0
}
```

---

### 3.25 Shift Management

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/shift/list` | 🔐 | List all shifts |
| POST | `/shift/add` | 🔐 | Create shift |
| POST | `/shift/update` | 🔐 | Update shift |
| POST | `/shift/delete` | 🔐 | Delete shift |
| POST | `/shift/assign` | 🔐 | Assign shift to user |

---

### 3.26 Settings

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/smtps` | 🔐 | List SMTP settings |
| PUT | `/smtp` | 🔐 | Create SMTP |
| GET | `/smtp/{id}` | 🔐 | View SMTP |
| POST | `/smtp/{id}` | 🔐 | Update SMTP |
| DELETE | `/smtp/{id}` | 🔐 | Delete SMTP |
| GET | `/sms-setting` | 🔐 | List SMS settings |
| PUT | `/setting-sms` | 🔐 | Create SMS provider |
| GET | `/setting-sms/{id}` | 🔐 | View SMS provider |
| POST | `/setting-sms/{id}` | 🔐 | Update SMS provider |
| DELETE | `/sms-delete/{id}` | 🔐 | Delete SMS provider |
| GET | `/voip-configurations` | 🔐 | VoIP configurations |
| PUT | `/voip-configuration` | 🔐 | Create VoIP config |
| GET | `/voip-configuration/{id}` | 🔐 | View VoIP config |
| POST | `/voip-configuration/{id}` | 🔐 | Update VoIP config |
| GET | `/delete-voip-configuration/{id}` | 🔐 | Delete VoIP config |
| GET | `/sip-gateways` | 🔐 | List SIP gateways |
| PUT | `/sip-gateway` | 🔐 | Create SIP gateway |
| GET | `/sip-gateways/{id}` | 🔐 | View SIP gateway |
| GET | `/sip-gateway-delete/{id}` | 🔐 | Delete SIP gateway |
| GET | `/allowed-ips` | 🔐 | Allowed IP list |
| PUT | `/allowed-ip` | 🔐 | Add allowed IP |
| POST | `/crm-system-setting` | 🔐 | Save CRM system settings |
| GET | `/crm-email-setting` | 🔐 | CRM email settings |
| GET | `/custom-field-labels` | 🔐 | Custom field labels |
| PUT | `/custom-field-label` | 🔐 | Create custom field label |
| GET | `/area-code-list` | 🔐 | Area code list |
| GET | `/get-timezone-list` | 🔐 | Timezone list |

---

### 3.27 Billing & Wallet

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/wallet/balance` | 🔐 | Main wallet balance |
| GET | `/wallet/transactions` | 🔐 | Wallet transaction history |
| GET | `/stripe/get-customer-id` | 🔐 | Get Stripe customer ID |
| GET | `/stripe/get-customer-payment-method` | 🔐 | List saved payment methods |
| POST | `/stripe/save-card` | 🔐 | Save new card |
| POST | `/stripe/charge` | 🔐 | Charge card |
| POST | `/stripe/delete-stripe-payment_method` | 🔐 | Delete payment method |
| POST | `/checkout` | 🔐 | Purchase package |
| GET | `/cart` | 🔐 | Get cart items |
| POST | `/cart/add/{packageName}` | 🔐 | Add package to cart |
| GET | `/orders` | 🔐 | Order history |
| GET | `/packages` | 🔓 | Available packages |

---

### 3.28 AI Coach & AI Analytics

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/ai-coach-api` | 🔓 | AI coaching system (cron) |
| POST | `/chat-ai-history` | 🔐 | Chat AI history |
| POST | `/send-text-to-ai` | 🔐 | Send message to Chat AI |
| GET | `/chat-ai-setting` | 🔐 | Chat AI settings |
| POST | `/call-matrix-report` | 🔐 | Create call matrix analysis |
| POST | `/call-matrix/process` | 🔐 | Process matrix |
| GET | `/call-matrix-report/{reference_id}` | 🔐 | View analysis results |
| GET | `/pdf-reader-setting` | 🔐 | PDF reader / AI settings |
| POST | `/prompts` | 🔐 | List AI prompts |
| POST | `/prompts` | 🔐 | Create prompt |
| GET | `/prompts/{id}` | 🔐 | View prompt |
| POST | `/prompts/update/{id}` | 🔐 | Update prompt |
| POST | `/prompts/{id}/functions` | 🔐 | Save prompt functions |

---

### 3.29 Inbound Call Notifications (Real-Time)

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/inbound-call-popup-notification` | 🔓 (token param) | Trigger ringing popup for extensions |
| GET | `/inbound-call-popup-received` | 🔓 | Mark inbound call as received |
| GET | `/inbound-call-popup-completed` | 🔓 | Mark inbound call as completed |
| POST | `/inbound-call-popup` | 🔓 | Inbound call popup |
| GET | `/predictive-dial-call` | 🔓 (token) | Predictive dialer event |

> **Note:** Inbound call ringing is delivered via **Pusher**:
> - Channel: `my-channel`
> - Event: `my-event`
> - Payload: `{ user_ids, platform: "call", event: "ringing", number }`

---

### 3.30 Recycle Rules

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/recycle-rule` | 🔐 | List recycle rules |
| POST | `/add-recycle-rule` | 🔐 | Create recycle rule |
| POST | `/edit-recycle-rule` | 🔐 | Update recycle rule |
| POST | `/delete-leads-rule` | 🔐 | Delete rule |
| POST | `/search-recycle-rule` | 🔐 | Search rules |

---

## 4. Feature Modules

### Module 1: Authentication
**What it does:** JWT-based multi-role authentication, 2FA (SMS/Google Authenticator), API key auth, OAuth (Google/Twitter), password reset.

**Key frontend tasks:** Store JWT token and user profile. Implement 2FA step-up if `otpId` is returned. Handle token expiry by redirecting to login.

---

### Module 2: Dashboard
**What it does:** Returns high-level KPIs — total users, campaigns, DIDs, leads, SMS counts, voicemail counts, callback counts. Supports time range filtering. Dashboard layout state is stored per-user.

**Key frontend tasks:** Fetch dashboard summary on page load. Poll or cache for 5 minutes. Use `dashboard-state` to persist widget layout.

---

### Module 3: Dialer Operations
**What it does:** Full dialer session management — agent extension login, call initiation, hang-up, DTMF, voicemail drop, disposition saving, call transfer (warm/cold), supervisor listen/barge.

**Key frontend tasks:** This is the most critical real-time module. Agent goes through: Login → Select Campaign → Extension Login → Get Lead → Call Number → DTMF if needed → Hang Up → Save Disposition → Get Next Lead.

---

### Module 4: Campaign Management
**What it does:** Create, manage, and schedule outbound dialing campaigns. Associate lists of leads. Configure call time windows, dial ratio, campaign type, and DID.

**Key frontend tasks:** Campaign CRUD interface. Assign lists to campaigns. View campaign performance.

---

### Module 5: CRM (Leads/Contacts)
**What it does:** Full contact/lead lifecycle — create, update, label, assign to agents, manage documents, set statuses, schedule tasks, send to lenders, notes/notifications.

**Key frontend tasks:** Lead list view with search/filter. Lead detail page with all activity. Document upload/management. Lender submission flow.

---

### Module 6: Calls / CDR Reports
**What it does:** Full call detail records with multi-filter search (number, date range, extension, disposition, DID, type). Live call monitoring. Daily call report generation. Transfer reports.

**Key frontend tasks:** Report page with date range picker and multi-select filters. CDR table with pagination. Live calls board.

---

### Module 7: DID / IVR / Ring Groups / Telephony Settings
**What it does:** Buy and configure phone numbers (DIDs) from multiple providers. Build IVR trees with menu routing. Create ring groups. Configure call timing schedules and holidays.

**Key frontend tasks:** DID management with provider integrations. IVR builder (tree view UI). Ring group management.

---

### Module 8: SMS Center
**What it does:** Real-time 2-way SMS per DID. View conversations by DID. Send SMS. Unread counts. Pusher integration for real-time new SMS notifications.

**Key frontend tasks:** Conversation list view by DID. Chat-style message thread. Send SMS form.

---

### Module 9: AI Features
**What it does:** SMS AI auto-responder (configurable AI settings), Chat AI interface, AI Call Coach (post-call analysis), Call Matrix analysis, Gmail AI analysis.

**Key frontend tasks:** AI settings page. SMS AI conversation view. Call matrix report viewer.

---

### Module 10: Team Chat
**What it does:** Internal team messaging with group/direct conversations. WebRTC calling between agents. Presence tracking. File attachments. Widget token management.

**Key frontend tasks:** Full chat UI with Pusher real-time messages. WebRTC call setup using ICE servers. Presence indicator.

---

### Module 11: Attendance / HR
**What it does:** Agents clock in/out, take breaks. Admins manage shifts and view attendance reports. Late/early alerts.

**Key frontend tasks:** Clock-in button on agent dashboard. Admin attendance grid. Report pages (daily/weekly/monthly).

---

### Module 12: Billing / Wallet
**What it does:** Stripe payment integration. Wallet system for call billing. Package subscriptions. Cart and order management.

**Key frontend tasks:** Payment method management page. Wallet balance widget. Package selection and checkout.

---

## 5. Frontend Usage Flow

### 5.1 Login Flow

```
1. User opens /login page
2. POST /authentication
   { email, password, device: "desktop_app" }
3. Check response:
   a. If otpId present → show OTP form
      → POST /verify_google_otp { otp, otpId }
   b. If success:
      - Store in localStorage/state:
        { token, user, extension, alt_extension, server, domain, secret, parent_id }
4. Redirect to /dashboard
```

---

### 5.2 Agent Dashboard Load

```
1. GET /profile                    → fetch full profile
2. POST /dashboard                 → KPI summary
3. POST /unread-sms-count          → SMS badge
4. POST /unread-mailbox            → voicemail badge
5. GET  /callback-reminder/status  → pending callbacks
6. Subscribe to Pusher channel 'my-channel' for:
   - Incoming call events (event: 'ringing')
   - SMS notifications
```

---

### 5.3 Start Dialing Campaign

```
1. POST /agent-campaign
   → Get list of available campaigns for agent's extension
2. Agent selects campaign
3. POST /extension-login { campaign_id }
   → Response: { success, lead_id, number, ... }
4. If success:
   a. GET /get-lead → fetch lead data to display
   b. Display lead info panel
5. POST /call-number { campaign_id, lead_id, number, id: 1 }
   → Call is placed via Asterisk
6. During call:
   → POST /dtmf { digit } if needed
   → POST /voicemail-drop if agent leaves voicemail
7. POST /hang-up { id: 1 }
8. GET /disposition_by_campaignId { campaign_id }
   → Load disposition options
9. POST /save-disposition { campaign_id, lead_id, disposition_id, notes }
10. Loop back to step 4
```

---

### 5.4 Incoming Call Handling

```
1. Backend (cron/webhook) calls /inbound-call-popup-notification
   → Pusher event pushed to frontend:
     {
       user_ids: [42],
       platform: "call",
       event: "ringing",
       number: "5551234567"
     }
2. Frontend listens on Pusher 'my-channel' / 'my-event'
3. On 'ringing' event: show incoming call popup
   - Display caller number
   - Show "Answer" / "Reject" buttons
4. Agent answers via SIP/WebRTC (Webphone)
5. On answer: GET /inbound-call-popup-received?...
6. Load lead data: POST /search-leads { number }
7. On call end: GET /inbound-call-popup-completed?...
8. POST /save-disposition
```

---

### 5.5 Outbound Click-to-Call (CRM)

```
1. Agent on CRM lead page
2. Click phone icon
3. GET /asterisk-login?extension=1001&number=5551234567&lead_id=1042
   → If extension is logged in → initiates call
   → If not logged in → returns login status
4. Call in progress (SIP handles audio)
5. GET /asterisk-hang-up?extension=1001&number=5551234567&lead_id=1042
6. POST /save-disposition
```

---

### 5.6 Call Transfer Flow

```
Cold (Blind) Transfer:
1. POST /check-extension-live-for-transfer { extension: "1002" }
2. If available: POST /direct-call-transfer { target_extension: "1002" }

Warm (Attended) Transfer:
1. POST /warm-call-transfer-c2c-crm { target_extension: "1002" }
   → Opens consultation call with target
2. Agent talks to target
3. POST /merge-call-with-transfer
   → Merges customer into conference
4. POST /leave-conference-transfer
   → Agent leaves, customer and target remain
```

---

### 5.7 Contact Management Flow

```
1. GET /leads (with filters)         → Display lead list
2. GET /lead/{id}                    → Open lead detail
3. GET /crm-labels                   → Load label options
4. GET /leadStatus                   → Load status options
5. GET /documents/{lead_id}          → Load documents
6. GET /crm-scheduled-task/{lead_id} → Load tasks
7. POST /lead/{id}/edit              → Save changes
8. POST /lead-status/{id}/edit       → Update status
9. PUT /document                     → Upload document
10. GET /notifications-crm           → Load activity log
```

---

### 5.8 Reports Generation

```
1. User selects filters:
   - date range, campaign, disposition, extension, number
2. POST /report (with filter params)
   → Returns paginated CDR data
3. Optional: POST /daily-call-report
   → Generate and email report
4. GET /daily-call-report             → List generated reports
5. GET /daily-call-report/{logId}     → View report
6. POST /disposition-wise-call        → Disposition breakdown chart
7. POST /extension-summary            → Per-agent performance
```

---

## 6. Recommended Frontend Architecture

### 6.1 API Service Layer

Create a centralized Axios instance:

```typescript
// src/services/api.ts
import axios from 'axios';

const api = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL,
  headers: { 'Content-Type': 'application/json' }
});

// Request interceptor: attach token
api.interceptors.request.use((config) => {
  const token = authStore.getToken();
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

// Response interceptor: handle 401
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      authStore.logout();
      router.push('/login');
    }
    return Promise.reject(error);
  }
);
```

Group service files by module:
```
src/services/
  auth.service.ts          → POST /authentication, /forgot-password
  dashboard.service.ts     → POST /dashboard
  dialer.service.ts        → /extension-login, /call-number, /hang-up, etc.
  campaign.service.ts      → campaign CRUD
  lead.service.ts          → CRM lead operations
  report.service.ts        → CDR and reports
  sms.service.ts           → SMS operations
  did.service.ts           → DID management
  teamchat.service.ts      → Team chat
  attendance.service.ts    → Clock in/out
  billing.service.ts       → Wallet and Stripe
```

---

### 6.2 State Management (Zustand / Redux Toolkit)

Key stores needed:

| Store | Data |
|-------|------|
| `authStore` | `{ token, user, extension, server, domain, secret, parent_id }` |
| `dialerStore` | `{ campaign, lead, callState: 'idle|ringing|in-call|wrapping', extension_logged_in }` |
| `smsStore` | `{ conversations, unreadCount, activeConversation }` |
| `notificationStore` | `{ inboundCalls, callbacks, unreadSms, unreadVoicemail }` |
| `dashboardStore` | `{ metrics, lastFetched }` |
| `uiStore` | `{ sidebarOpen, activeModal, loading }` |

---

### 6.3 Authentication Handling

```typescript
// On login success:
localStorage.setItem('token', data.token);
localStorage.setItem('user', JSON.stringify(data));
authStore.setUser(data);
// Initialize Pusher with user context
pusherService.subscribe(data.id, data.parent_id);

// On logout:
await POST('/user-logout');
localStorage.clear();
pusherService.unsubscribe();
router.push('/login');
```

---

### 6.4 Real-Time (Pusher) Setup

```typescript
// src/services/pusher.service.ts
import Pusher from 'pusher-js';

const pusher = new Pusher(process.env.NEXT_PUBLIC_PUSHER_KEY!, {
  cluster: process.env.NEXT_PUBLIC_PUSHER_CLUSTER!,
  authEndpoint: `${API_URL}/team-chat/pusher/auth`,
  auth: {
    headers: { Authorization: `Bearer ${authStore.getToken()}` }
  }
});

// Subscribe to inbound call notifications:
const channel = pusher.subscribe('my-channel');
channel.bind('my-event', (data: PusherPayload) => {
  if (data.message?.event === 'ringing') {
    dialerStore.triggerIncomingCall(data.message.number);
  }
});
```

---

### 6.5 Error Handling

```typescript
// Standardized error handler
function handleApiError(error: AxiosError) {
  const message = error.response?.data?.message ?? 'An error occurred';
  const code = error.response?.status;

  if (code === 401) return; // handled by interceptor
  if (code === 403) toast.error('Permission denied');
  else if (code === 422) toast.error(`Validation: ${message}`);
  else if (code === 500) toast.error('Server error. Please try again.');
  else toast.error(message);
}
```

---

### 6.6 Loading States

Use React Query or SWR for all read operations:
```typescript
const { data: dashboard, isLoading } = useQuery({
  queryKey: ['dashboard', { startTime, endTime }],
  queryFn: () => dashboardService.getSummary({ startTime, endTime }),
  staleTime: 5 * 60 * 1000 // 5 minutes (matches server cache)
});
```

---

## 7. UI Pages Required

### Page 1: Login (`/login`)
**APIs:** `POST /authentication`, `POST /verify_google_otp`
- Email/password form
- 2FA step-up form (conditional)
- Forgot password link

### Page 2: Dashboard (`/dashboard`)
**APIs:** `POST /dashboard`, `POST /cdr-dashboard-summary`, `POST /disposition-wise-call`, `POST /cdr-count-range`, `POST /extension-summary`, `POST /live-call`, `GET /callback-reminder/status`
- KPI cards (users, campaigns, DIDs, leads)
- Call volume chart (by date range)
- Disposition pie/bar chart
- Live calls panel
- Recent callbacks

### Page 3: Dialer (`/dialer`)
**APIs:** `POST /agent-campaign`, `POST /extension-login`, `GET /get-lead`, `POST /call-number`, `POST /hang-up`, `POST /save-disposition`, `POST /disposition_by_campaignId`, `POST /dtmf`, `POST /voicemail-drop`, `POST /redial-call`, `POST /view-notes/{leadId}`, `POST /listen-call`, `POST /barge-call`
- Campaign selector
- Lead info panel
- Call controls (dial, hang up, DTMF keypad, hold, transfer)
- Disposition form
- Notes panel
- Webphone widget (SIP.js)

### Page 4: Campaign Management (`/campaigns`)
**APIs:** `GET /campaigns`, `POST /add-campaign`, `POST /edit-campaign`, `POST /delete-campaign`, `POST /copy-campaign`, `GET /campaign-type`, `POST /campaign-list`, `POST /campaign/assign-lists`, `POST /campaign/{id}/schedule`
- Campaign list/grid
- Create/edit campaign modal
- List assignment
- Call schedule configuration

### Page 5: Contacts / CRM Leads (`/crm/leads`)
**APIs:** `POST /leads`, `PUT /lead/add`, `GET /lead/{id}`, `POST /lead/{id}/edit`, `GET /leadStatus`, `GET /crm-labels`, `POST /leads/add/opener`, `POST /leads/add/closer`
- Lead list with search, filter, sort, pagination
- Lead card/row with quick actions
- Bulk import (CSV)

### Page 6: Lead Detail (`/crm/leads/{id}`)
**APIs:** `GET /lead/{id}`, `POST /lead/{id}/edit`, `POST /lead/{id}/view`, `GET /documents/{lead_id}`, `PUT /document`, `GET /crm-scheduled-task/{lead_id}`, `POST /leadTask/add`, `GET /notifications-crm`, `GET /send-lead-to-lenders/{id}`, `POST /lender-status/{id}/edit`, `GET /showlenders/{id}`
- Lead info with inline edit
- Timeline / activity feed
- Documents tab
- Tasks tab
- Lender submission tab
- Call history tab

### Page 7: Lists & Leads (Dialer-side) (`/lists`)
**APIs:** `POST /list`, `POST /add-list`, `POST /edit-list`, `GET /list/{id}/content`, `POST /list-header`, `POST /search-leads`, `POST /upload-exclude-number`
- List cards with lead count
- Lead data grid per list
- Upload/import leads

### Page 8: Reports (`/reports/cdr`)
**APIs:** `POST /report`, `POST /get-cdr`, `POST /daily-call-report`, `GET /daily-call-report`, `POST /live-call`, `POST /transfer-report`, `POST /login-history`, `POST /extension-summary`, `GET /get-timezone-list`
- Date range + multi-filter panel
- CDR table (paginated)
- Export (CSV/PDF)
- Live calls sub-page

### Page 9: DID Management (`/settings/dids`)
**APIs:** `GET /did`, `POST /add-did`, `POST /save-edit-did`, `POST /delete-did`, `POST /buy-save-selected-did`, `POST /get-did-list-from-sale`, `POST /get-call-timings`, `POST /save-call-timings`, `POST /get-all-holidays`, `POST /save-holiday-detail`
- DID list
- Buy DID wizard
- Call schedule per DID
- Holiday calendar

### Page 10: IVR Builder (`/settings/ivr`)
**APIs:** `POST /ivr`, `POST /add-ivr`, `POST /edit-ivr`, `POST /ivr-menu`, `POST /add-ivr-menu`, `POST /dest-type`, `GET /audio-message`, `POST /add-audio-message`
- IVR tree visualization
- Menu node editor
- Audio file manager

### Page 11: SMS Center (`/sms`)
**APIs:** `GET /sms-did-list-crm`, `GET/POST /sms-by-did`, `POST /send-sms`, `GET /unread-sms-count`
- DID selector sidebar
- Conversation list
- Chat thread
- Send message input

### Page 12: Extensions/Agents (`/settings/extensions`)
**APIs:** `GET /extension`, `POST /add-extension`, `POST /edit-extension-save`, `GET /role`, `GET /extension-group`, `POST /check-extension-live`
- Agent list with online status
- Create/edit agent modal
- Role assignment

### Page 13: Extension Groups (`/settings/groups`)
**APIs:** `GET /extension-group`, `PUT /extension-group`, `PATCH /extension-group/{id}`, `DELETE /extension-group/{id}`
- Group list
- Create/edit group
- Assign extensions

### Page 14: Dispositions (`/settings/dispositions`)
**APIs:** `POST /disposition`, `POST /add-disposition`, `POST /edit-disposition`, `POST /status-update-disposition`
- Disposition list
- Create/edit form

### Page 15: Call Timers (`/settings/call-timers`)
**APIs:** `GET /call-timers`, `GET /call-timers/{id}`, `POST /call-timers`, `POST /call-timers/{id}`, `DELETE /call-timers/{id}`
- Timer schedule list
- Create/edit schedule

### Page 16: Voicemail Center (`/voicemail`)
**APIs:** `POST /mailbox`, `POST /delete-mailbox`, `POST /add-voice-mail-drop`, `GET /view-voicemail`
- Voicemail inbox list
- Audio player inline
- Voicemail drop management

### Page 17: Fax (`/fax`)
**APIs:** `POST /fax`, `POST /send-fax`, `POST /receive-fax-list`, `POST /get-unread-fax-count`
- Received/Sent fax list
- Send fax form
- PDF viewer

### Page 18: Callbacks (`/callbacks`)
**APIs:** `POST /callback`, `POST /callback/edit`, `GET /callback-reminder/show`
- Callback schedule list
- Calendar view
- Reminder modal

### Page 19: Team Chat (`/chat`)
**APIs:** All `/team-chat/*` endpoints + Pusher
- Conversation sidebar
- Message thread
- User search
- File upload
- WebRTC call UI

### Page 20: Reports — AI / Call Matrix (`/reports/ai`)
**APIs:** `POST /call-matrix-report`, `POST /call-matrix/process`, `GET /call-matrix-report/{id}`, `GET /ai-setting/users-cli-name`
- Upload CDR for analysis
- Analysis result viewer

### Page 21: Attendance (`/attendance`)
**APIs:** `POST /attendance/clock-in`, `POST /attendance/clock-out`, `GET /attendance/status`, `POST /attendance/break/start`, `POST /attendance/break/end`, `GET /attendance/my-attendance`
- Clock-in/out button
- Break timer
- Personal attendance calendar

### Page 22: Attendance Reports (`/attendance/reports`)
**APIs:** `POST /attendance/report/daily`, `/weekly`, `/monthly`, `/summary`, `/alerts`, `POST /attendance/list`
- Date range filter
- Agent attendance grid
- Late/early alerts table

### Page 23: Shift Management (`/settings/shifts`)
**APIs:** `POST /shift/list`, `POST /shift/add`, `POST /shift/update`, `POST /shift/delete`, `POST /shift/assign`
- Shift templates list
- Create/edit shift
- Assign shifts to agents

### Page 24: DNC Management (`/settings/dnc`)
**APIs:** `POST /dnc`, `POST /add-dnc`, `POST /delete-dnc`, `POST /upload-dnc`
- DNC number list
- Add/upload form

### Page 25: Marketing Campaigns (`/marketing`)
**APIs:** `GET /marketing-campaigns`, `PUT /marketing-campaign`, `GET /marketing-campaigns-schedule/{id}`, `PUT /marketing-campaign-schedule`, `PUT /marketing-campaign-schedule-sms`
- Email/SMS campaign list
- Campaign builder
- Schedule management

### Page 26: Ringless Voicemail (`/rvm`)
**APIs:** All `/ringless/*` endpoints
- RVM campaign list
- Create RVM campaign
- Reports

### Page 27: SMS AI Campaigns (`/smsai`)
**APIs:** All `/smsai/*` endpoints
- AI campaign list
- Create campaign
- Conversation history
- Reports

### Page 28: Gmail Mailbox (`/gmail`)
**APIs:** All `/gmail/*` endpoints
- Connect Gmail button (OAuth)
- Inbox/Sent/Labels view
- Read/send email
- Notification settings

### Page 29: Billing & Subscription (`/billing`)
**APIs:** `GET /wallet/balance`, `GET /wallet/transactions`, `GET /stripe/get-customer-payment-method`, `POST /stripe/save-card`, `POST /checkout`, `GET /orders`, `GET /packages`
- Wallet balance card
- Payment method management
- Package selection
- Order history

### Page 30: Settings (General) (`/settings`)
**APIs:** SMTP, SMS settings, VoIP config, SIP gateways, Allowed IPs, custom fields, CRM system settings
- Tabbed settings page
- Each section: list → create/edit modal

---

## 8. Frontend Folder Structure Recommendation

```
src/
├── app/                          # Next.js 14 App Router
│   ├── (auth)/
│   │   ├── login/
│   │   └── forgot-password/
│   ├── (dashboard)/
│   │   ├── dashboard/
│   │   ├── dialer/
│   │   ├── campaigns/
│   │   ├── crm/
│   │   │   └── leads/
│   │   │       └── [id]/
│   │   ├── lists/
│   │   ├── sms/
│   │   ├── voicemail/
│   │   ├── fax/
│   │   ├── callbacks/
│   │   ├── reports/
│   │   │   ├── cdr/
│   │   │   └── ai/
│   │   ├── attendance/
│   │   │   └── reports/
│   │   ├── marketing/
│   │   ├── rvm/
│   │   ├── smsai/
│   │   ├── gmail/
│   │   ├── chat/
│   │   ├── billing/
│   │   └── settings/
│   │       ├── extensions/
│   │       ├── groups/
│   │       ├── dispositions/
│   │       ├── dids/
│   │       ├── ivr/
│   │       ├── ring-groups/
│   │       ├── dnc/
│   │       ├── shifts/
│   │       ├── smtp/
│   │       ├── sip-gateways/
│   │       └── voip/
│   └── layout.tsx
│
├── components/
│   ├── ui/                       # Base components (buttons, inputs, tables)
│   ├── layout/                   # Sidebar, topbar, breadcrumb
│   ├── dialer/
│   │   ├── DialerPanel.tsx        # Main dialer UI
│   │   ├── CallControls.tsx       # Dial/hang up/DTMF/transfer buttons
│   │   ├── LeadInfoPanel.tsx      # Current lead display
│   │   ├── DispositionForm.tsx    # Post-call disposition
│   │   ├── WebphoneWidget.tsx     # SIP.js softphone
│   │   └── IncomingCallModal.tsx  # Ringing popup
│   ├── crm/
│   │   ├── LeadCard.tsx
│   │   ├── LeadTimeline.tsx
│   │   ├── LeadDocuments.tsx
│   │   └── LeadLenderPanel.tsx
│   ├── sms/
│   │   ├── ConversationList.tsx
│   │   └── MessageThread.tsx
│   ├── chat/
│   │   ├── ChatSidebar.tsx
│   │   ├── MessageList.tsx
│   │   └── WebRTCCallModal.tsx
│   ├── reports/
│   │   ├── CdrTable.tsx
│   │   └── CallVolumeChart.tsx
│   └── shared/
│       ├── DataTable.tsx          # Reusable paginated table
│       ├── DateRangePicker.tsx
│       └── StatusBadge.tsx
│
├── hooks/
│   ├── useAuth.ts
│   ├── useDialer.ts              # Dialer session logic
│   ├── usePusher.ts              # Pusher subscription
│   ├── useWebphone.ts            # SIP.js integration
│   ├── useInboundCall.ts         # Incoming call handling
│   └── useAttendance.ts
│
├── services/
│   ├── api.ts                    # Axios instance + interceptors
│   ├── auth.service.ts
│   ├── dashboard.service.ts
│   ├── dialer.service.ts
│   ├── campaign.service.ts
│   ├── lead.service.ts
│   ├── sms.service.ts
│   ├── report.service.ts
│   ├── did.service.ts
│   ├── teamchat.service.ts
│   ├── attendance.service.ts
│   ├── billing.service.ts
│   ├── gmail.service.ts
│   └── pusher.service.ts
│
├── store/                        # Zustand stores
│   ├── auth.store.ts
│   ├── dialer.store.ts
│   ├── notification.store.ts
│   ├── sms.store.ts
│   └── ui.store.ts
│
├── types/
│   ├── auth.types.ts
│   ├── campaign.types.ts
│   ├── dialer.types.ts
│   ├── lead.types.ts
│   ├── sms.types.ts
│   └── report.types.ts
│
├── utils/
│   ├── dateFormat.ts
│   ├── phoneFormat.ts
│   └── permissions.ts            # Role-based access helper
│
└── constants/
    ├── routes.ts
    └── permissions.ts
```

---

## 9. Webphone Integration

### 9.1 Architecture Overview

```
Browser (SIP.js/JsSIP) ←→ WebSocket (WSS) ←→ Asterisk SIP Server
                                                        ↕
Frontend ←→ Backend API (/extension-login, /call-number, /hang-up)
```

The backend handles Asterisk AMI commands. The webphone uses SIP over WebSocket to register the agent's extension and handle audio.

---

### 9.2 SIP Credentials from Login

After login, extract:
```javascript
const sipConfig = {
  server:        data.server,        // "sip.yourserver.com"
  domain:        data.domain,        // "yourserver.com"
  extension:     data.alt_extension, // "91001" (use alt_extension for webphone)
  password:      atob(data.secret),  // base64-decoded SIP password
  display_name:  `${data.first_name} ${data.last_name}`,
  wsUri:        `wss://${data.server}:8089/ws`
};
```

> **Important:** Always use `alt_extension` (not `extension`) for SIP registration. The backend resolves which extension to use based on `dialer_mode`.

---

### 9.3 SIP.js Setup

```typescript
// src/hooks/useWebphone.ts
import { Web } from 'sip.js';

export function useWebphone() {
  const [userAgent, setUserAgent] = useState<Web.SimpleUser | null>(null);
  const [callState, setCallState] = useState<'idle'|'ringing'|'in-call'>('idle');

  const initWebphone = async (sipConfig: SipConfig) => {
    const simpleUser = new Web.SimpleUser(`wss://${sipConfig.server}:8089/ws`, {
      aor: `sip:${sipConfig.extension}@${sipConfig.domain}`,
      userAgentOptions: {
        authorizationUsername: sipConfig.extension,
        authorizationPassword: sipConfig.password,
        displayName: sipConfig.display_name
      },
      media: {
        remote: { audio: document.getElementById('remote-audio') as HTMLAudioElement }
      },
      delegate: {
        onCallReceived: () => setCallState('ringing'),
        onCallAnswered: () => setCallState('in-call'),
        onCallHangup: () => setCallState('idle')
      }
    });

    await simpleUser.connect();
    await simpleUser.register();
    setUserAgent(simpleUser);
  };

  const answer = () => userAgent?.answer();
  const decline = () => userAgent?.decline();
  const hangup = () => userAgent?.hangup();
  const sendDtmf = (digit: string) => userAgent?.sendDTMF(digit);

  return { initWebphone, callState, answer, decline, hangup, sendDtmf };
}
```

---

### 9.4 Call State Machine

```
IDLE
  ↓ POST /extension-login
LOGGED_IN
  ↓ POST /call-number  (or inbound SIP INVITE)
IN_CALL
  ├── POST /dtmf          → send DTMF
  ├── POST /voicemail-drop → drop voicemail
  ├── POST /listen-call   → supervisor listen mode
  ├── POST /barge-call    → supervisor barge
  ├── POST /direct-call-transfer → cold transfer
  └── POST /hang-up
WRAPPING  (show disposition form)
  ↓ POST /save-disposition
READY (back to LOGGED_IN, fetching next lead)
```

---

### 9.5 Webphone Toggle

```typescript
// Switch between hardware phone and webphone
const switchWebphone = async (enable: boolean) => {
  await api.post('/webphone/switch-access', { webphone: enable ? 1 : 0 });
  const { data } = await api.get('/webphone/status');
  dialerStore.setWebphoneEnabled(data.webphone === 1);
};
```

---

### 9.6 Inbound Call Ringing via Pusher

```typescript
// Listen for server-pushed ringing events
pusherChannel.bind('my-event', (payload) => {
  const msg = payload.message;
  if (msg.platform === 'call' && msg.event === 'ringing') {
    const currentUserId = authStore.getUser().id;
    if (msg.user_ids.includes(currentUserId)) {
      // Show incoming call notification
      notificationStore.setIncomingCall({
        number: msg.number,
        timestamp: new Date()
      });
      // SIP.js handles the actual audio ringing
    }
  }
});
```

---

## 10. Security Recommendations

### 10.1 Token Storage

```typescript
// PREFERRED: httpOnly cookie (if backend supports it)
// Set-Cookie: token=<jwt>; HttpOnly; Secure; SameSite=Strict

// ACCEPTABLE for SPA: sessionStorage (cleared on tab close)
sessionStorage.setItem('token', data.token);

// AVOID: localStorage (accessible to XSS scripts)
// If you must use localStorage, ensure thorough CSP headers
```

### 10.2 SIP Password Handling

```typescript
// The SIP secret is returned as base64 + uuencode
// Decode only when needed, never store plaintext
const sipPassword = atob(data.secret); // decode just-in-time
// Don't store sipPassword in state — re-derive from stored encoded secret
const sipConfig = {
  password: atob(authStore.getEncodedSecret())
};
```

### 10.3 Role-Based Access Control (RBAC)

```typescript
// src/utils/permissions.ts
export const LEVELS = {
  SUPER_ADMIN: 10,
  ADMIN: 7,
  MANAGER: 5,
  AGENT: 1
};

export function canAccess(userLevel: number, requiredLevel: number): boolean {
  return userLevel >= requiredLevel;
}

// Component usage:
{canAccess(user.level, LEVELS.ADMIN) && <AdminPanel />}
{canAccess(user.level, LEVELS.MANAGER) && <SuperviseButton />}
```

### 10.4 API Key Protection

- Never expose `EASIFY_APP_KEY`, `PUSHER_APP_KEY`, or `STRIPE_SECRET_KEY` in frontend code
- Use environment variables with `NEXT_PUBLIC_` prefix only for safe public keys (Pusher App Key, Stripe Publishable Key)
- All secret keys stay server-side only

### 10.5 Input Sanitization

- Always sanitize phone numbers before display: strip `<`, `>`, script tags
- Sanitize lead notes and SMS content before rendering (use DOMPurify for HTML)
- Validate phone number format before calling `/call-number`

### 10.6 HTTPS / WSS Enforcement

- All API calls must use HTTPS
- WebSocket (SIP) must use WSS (`wss://`)
- Set `Content-Security-Policy` headers to restrict script sources

### 10.7 Token Expiry Handling

```typescript
// Check JWT expiry proactively
function isTokenExpired(token: string): boolean {
  const payload = JSON.parse(atob(token.split('.')[1]));
  return payload.exp < Math.floor(Date.now() / 1000);
}

// On app mount:
if (isTokenExpired(storedToken)) {
  authStore.logout();
  router.push('/login');
}
```

### 10.8 Pusher Channel Security

- Use Pusher private/presence channels for sensitive data (not just `my-channel`)
- Authenticate via `POST /team-chat/pusher/auth` for private channels
- Validate `user_ids` array in Pusher payloads before showing notifications

### 10.9 Multi-Tenant Data Isolation

- **Never** allow users to manually set `parent_id` in API requests
- The backend derives `parent_id` from the JWT token (via `$request->auth->parent_id`)
- If any API accidentally allows `parent_id` override, the backend rejects it with 401/403

### 10.10 IP Filtering Awareness

- Some accounts have `ip_filtering: 1` in the login response
- If the user is on a new IP, login will return `401 Unauthorised IP address`
- Frontend should inform the user to contact their admin to whitelist the IP
- Admin can use `POST /ip/whitelist-ip` to authorize IPs

---

## Appendix A: Standard Response Envelope

All authenticated API responses follow this pattern:

**Success:**
```json
{
  "success": true,
  "message": "Human readable message",
  "data": { ... }
}
```

**Paginated Success:**
```json
{
  "success": true,
  "message": "List retrieved",
  "data": [ ... ],
  "record_count": 150,
  "start": 0,
  "limit": 10
}
```

**Failure:**
```json
{
  "success": false,
  "message": "Error description",
  "errors": ["Detailed error 1", "Detailed error 2"]
}
```

**Validation Error (422):**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email field is required."],
    "campaign_id": ["The campaign id must be a number."]
  }
}
```

---

## Appendix B: Common HTTP Status Codes

| Code | Meaning | Frontend Action |
|------|---------|----------------|
| 200 | Success | Process response |
| 400 | Bad request / validation | Show field errors |
| 401 | Unauthenticated / expired token | Redirect to login |
| 402 | Payment required / dialing not permitted | Show upgrade prompt |
| 403 | Forbidden / insufficient role | Show permission error |
| 404 | Not found | Show 404 state |
| 422 | Validation failed | Show form errors |
| 500 | Server error | Show generic error toast |

---

## Appendix C: Pusher Events Reference

| Channel | Event | Triggered By | Payload |
|---------|-------|-------------|---------|
| `my-channel` | `my-event` (platform: `call`) | Inbound call arrival | `{ user_ids, event: "ringing", number }` |
| `my-channel` | `my-event` (platform: `sms`) | New inbound SMS | `{ user_ids, event: "sms", from, body }` |
| Private Presence | `client-typing` | Team chat typing | `{ user_id, conversation_uuid }` |

---

## Appendix D: Dialer Modes

| `dialer_mode` Value | Meaning |
|--------------------|---------|
| `webphone` | Agent uses browser-based SIP softphone |
| `extension` | Agent uses a physical SIP phone |
| `mobile_app` | Agent uses a mobile application |

The correct extension to use for SIP registration is determined by `dialer_mode`:
- **webphone** → use `alt_extension`
- **extension** → use `extension`
- **mobile/app** → use `app_extension`

---

*Document generated from codebase analysis of `/var/www/html/branch/dialer_backend_v2`*
*Framework: Lumen | Auth: JWT HS256 | Real-time: Pusher | Telephony: Asterisk AMI + SIP/WebRTC*
