# Call Transfer API — Developer Guide

## Overview

A single unified endpoint that replaces the old 3-step frontend flow for
warm call transfers.

| Old (3 round-trips)                        | New (1 round-trip)             |
|--------------------------------------------|--------------------------------|
| POST `/check-line-details`                 | ~~deprecated (internal only)~~ |
| POST `/check-extension-live-for-transfer`  | ~~deprecated (internal only)~~ |
| POST `/warm-call-transfer-c2c-crm`         | ~~deprecated (frontend use)~~  |
| —                                          | **POST `/call-transfer/initiate`** ✅ |

**Kept as-is (no change):**
- `POST /merge-call-with-transfer`
- `POST /leave-conference-transfer`

---

## Authentication

All endpoints require a valid JWT token:

```
Authorization: Bearer <token>
```

---

## POST `/call-transfer/initiate`

### Request — Extension Transfer

Transfer the customer call to another agent's extension.

```json
{
  "lead_id":               123,
  "customer_phone_number": "9876543210",
  "transfer_type":         "extension",
  "target":                "1002",
  "campaign_id":           45,
  "domain":                "crm"
}
```

### Request — External Transfer (DID / Phone)

Transfer the customer call to an external phone number.

```json
{
  "lead_id":               123,
  "customer_phone_number": "9876543210",
  "transfer_type":         "external",
  "target":                "+919876543210",
  "campaign_id":           45,
  "domain":                "dialer"
}
```

### Request Fields

| Field                   | Type    | Required | Description                                              |
|-------------------------|---------|----------|----------------------------------------------------------|
| `lead_id`               | integer | ✅       | Lead associated with the current call                    |
| `customer_phone_number` | string  | ✅       | Customer's phone number (as dialled)                     |
| `transfer_type`         | string  | ✅       | `"extension"` or `"external"`                            |
| `target`                | string  | ✅       | Extension number (e.g. `"1002"`) or phone (e.g. `"+919876543210"`) |
| `campaign_id`           | integer | ⚪       | Campaign ID. Defaults to `45`. Ignored when `domain=crm` |
| `domain`                | string  | ⚪       | `"crm"` forces campaign_id=45 (legacy behaviour)         |

---

### Success Response — HTTP 200

```json
{
  "status":              "success",
  "transfer_session_id": "a1b2c3d4e5f6a7b8",
  "state":               "ringing"
}
```

| Field                  | Description                                              |
|------------------------|----------------------------------------------------------|
| `status`               | Always `"success"` on success                            |
| `transfer_session_id`  | Unique 16-char hex ID for this transfer leg              |
| `state`                | Transfer state — currently always `"ringing"`            |

---

### Error Response — HTTP 422

```json
{
  "status":  "error",
  "message": "Extension 1002 is busy (already in a transfer)."
}
```

---

## Error Cases

| Scenario                                   | Message                                                                  |
|--------------------------------------------|--------------------------------------------------------------------------|
| Call already ended / wrong extension       | `No active call found. The call may have already ended...`               |
| Target extension not logged in             | `Extension 1002 is not online. Agent may be logged out or unavailable.`  |
| Target extension mid-transfer              | `Extension 1002 is busy (already in a transfer).`                        |
| Invalid phone number                       | `Invalid phone number '+abc'. Must contain 7–15 digits.`                 |
| Target extension not found in users table  | `Extension '1099' not found. Ensure the agent is registered.`            |
| AMI command failed                         | `Transfer initiation failed: <asterisk error>`                           |
| Bad `transfer_type` value                  | `Invalid transfer_type 'fax'. Allowed values: extension, external.`      |

---

## Frontend Integration Guide

### Before (3 round-trips, race conditions)

```javascript
// Step 1
const lineCheck = await api.post('/check-line-details', {
  lead_id, customer_phone_number, alt_extension, campaign_id
});
if (!lineCheck.data.success) return showError('Call ended');

// Step 2
const extCheck = await api.post('/check-extension-live-for-transfer', {
  forward_extension: target, domain
});
if (!extCheck.data.success) return showError('Extension unavailable');

// Step 3
await api.post('/warm-call-transfer-c2c-crm', {
  lead_id, customer_phone_number, warm_call_transfer_type: 'extension',
  forward_extension: target, domain
});
```

### After (1 round-trip, atomic)

```javascript
const result = await api.post('/call-transfer/initiate', {
  lead_id,
  customer_phone_number,
  transfer_type: 'extension',   // or 'external'
  target,                        // extension number or phone number
  campaign_id,
  domain
});

if (result.data.status === 'success') {
  console.log('Transfer session:', result.data.transfer_session_id);
  showState('ringing');
} else {
  showError(result.data.message);
}
```

---

## Backend Pipeline

```
POST /call-transfer/initiate
         │
         ▼
 CallTransferController::initiate()
         │
         ├─── Step 1: CallTransferService::validateCall()
         │            → queries line_detail table
         │            → throws RuntimeException if call not active
         │
         ├─── Step 2: CallTransferService::validateTarget()
         │
         │    if transfer_type == 'extension':
         │         → queries extension_live table
         │         → checks transfer_status != 2 (busy)
         │         → resolves SIP leg via dialer_mode
         │
         │    if transfer_type == 'external':
         │         → validates E.164 phone number format
         │
         └─── Step 3: CallTransferService::initiateTransfer()
                      → calls Dialer::getAsterisk()
                      → fires getWarmCallTransfer() or warmCallTransferDid()
                      → returns hex session ID on success
```

---

## Migration Guide: Old → New

### Extension transfer

| Old field               | New field        | Notes                              |
|-------------------------|------------------|------------------------------------|
| `warm_call_transfer_type = 'extension'` | `transfer_type = 'extension'` | renamed |
| `forward_extension`     | `target`         | same value                         |
| `campaign_id`           | `campaign_id`    | unchanged                          |
| `domain`                | `domain`         | unchanged                          |

### DID / external transfer

| Old field               | New field        | Notes                              |
|-------------------------|------------------|------------------------------------|
| `warm_call_transfer_type = 'did'` | `transfer_type = 'external'` | renamed |
| `did_number`            | `target`         | same value                         |

### Deprecated frontend calls (no longer needed)

- `POST /check-line-details` → logic moved to `validateCall()` (internal)
- `POST /check-extension-live-for-transfer` → logic moved to `validateTarget()` (internal)
- `POST /warm-call-transfer-c2c-crm` → replaced by `POST /call-transfer/initiate`

---

## Code Files

| File                                                          | Purpose                              |
|---------------------------------------------------------------|--------------------------------------|
| `app/Http/Controllers/CallTransferController.php`             | HTTP layer — validates input, calls service, returns JSON |
| `app/Services/CallTransferService.php`                        | Business logic — validateCall, validateTarget, initiateTransfer |
| `routes/web.php` (line with `call-transfer/initiate`)         | Route registration (inside jwt.auth group) |

---

## Keep-Alive Endpoints (unchanged, no migration needed)

These remain on `DialerController` and are unaffected:

```
POST /merge-call-with-transfer   — merge customer back into the 3-way call
POST /leave-conference-transfer  — agent leaves the conference
```
