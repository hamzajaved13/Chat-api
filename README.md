# Chat Application — RESTful API

Backend-only team chat application built with **Core PHP 8.1+** and
**MongoDB**. No framework, no frontend — just a small, deliberately simple
routing layer plus a clean Controller → Service → Repository architecture,
token-based authentication, and the full company → team → channel →
message hierarchy described in the project brief.

---

## 1. Tech stack

| Concern | Choice |
| --- | --- |
| Language | PHP 8.1+ |
| Database | MongoDB (via `mongodb/mongodb` composer package + `ext-mongodb`) |
| Auth | Opaque bearer tokens, hashed (SHA-256) before storage — **no PHP sessions** |
| Config | `.env` via `vlucas/phpdotenv` |
| Routing | Tiny hand-rolled regex router (`src/Core/Router.php`) — no framework |

---

## 2. Project structure

```
chat-api/
├── public/
│   └── index.php              # Front controller / entry point
├── routes/
│   └── api.php                 # All route definitions
├── src/
│   ├── Config/                 # Env loader, MongoDB connection
│   ├── Core/                   # Router, Request, Response, exceptions plumbing
│   ├── Middleware/              # AuthMiddleware (Bearer token enforcement)
│   ├── Controllers/            # Thin HTTP layer: validate input, call a Service, format Response
│   ├── Services/                # Business rules / authorization live here
│   ├── Repositories/            # All MongoDB reads/writes live here
│   ├── Validation/               # Minimal dependency-free request validator
│   ├── Support/                  # TokenGenerator, Security (hashing), MailService, Presenter
│   └── Exceptions/               # ApiException hierarchy -> mapped to HTTP status codes
├── database/
│   └── create_indexes.php       # Creates every index listed in section 14 of the spec
├── postman/
│   └── ChatApp.postman_collection.json
├── tests/
│   └── smoke_test.php           # Dependency-free tests for Router + Validator
├── storage/logs/                 # mail.log lands here when SMTP isn't configured
├── .env.example
└── composer.json
```

**Why this split:** controllers never touch MongoDB directly and services
never format an HTTP response — that boundary is what keeps authorization
rules unit-testable and stops the "everything lives in the route file"
problem the brief explicitly warns against (section 22).

---

## 3. Setup & installation

### Prerequisites
- PHP ≥ 8.1 with the `mongodb` extension (`pecl install mongodb`)
  - **Version note:** the extension and the `mongodb/mongodb` composer package must be on matching major versions. Extension **v2.x** (current, e.g. what ships with recent XAMPP/PECL installs) needs `mongodb/mongodb` **^2.0**; extension **v1.x** needs `mongodb/mongodb` **^1.17**. This project's `composer.json` allows either (`^1.17 || ^2.0`) so `composer install` resolves correctly against whichever extension version you have. Check yours with `php --ri mongodb` or `php -m | grep mongodb`.
- Composer
- A MongoDB deployment. **Signup uses a MongoDB multi-document
  transaction** to keep the User + Company + Team + Channel + memberships
  write atomic — this requires a **replica set** (a single-node replica
  set is fine for local dev: `mongod --replSet rs0` then `rs.initiate()`
  once in the `mongosh` shell). If you only have a standalone `mongod`,
  the app still works: `AuthService` detects that transactions aren't
  supported and automatically falls back to sequential writes with
  compensating cleanup on failure (see `AuthService::createWithCompensation`).

### Steps

```bash
# 1. Install dependencies
composer install

# 2. Configure environment
cp .env.example .env
# edit .env: MONGO_URI, MONGO_DB_NAME, token TTLs, mail settings

# 3. Create MongoDB indexes (section 14 of the spec)
php database/create_indexes.php

# 4. Run the API
php -S localhost:8000 -t public
```

The API is now live at `http://localhost:8000/api/...`.

### Verifying email locally

If `MAIL_HOST` is left blank in `.env` (the default), verification emails
are written to `storage/logs/mail.log` instead of being sent, e.g.:

```
2026-08-21 10:00:00 [LOGGED] To: alice@companya.test | Subject: Verify your email address
...
Token: 9f3a1c2e...
```

Copy that raw token into `POST /api/auth/verify-email` (or open the
printed link) to complete verification during testing.

---

## 4. Authentication

- `POST /api/auth/signup` → creates the user (`email_verification_pending`), their company, the **General Team**, the **Announcements** channel, memberships in both, and sends a verification email/token.
- `POST /api/auth/verify-email` → activates the account.
- `POST /api/auth/login` → requires a verified account; returns an opaque bearer token (also stored **hashed**, with an expiry, in `auth_tokens`).
- Every protected endpoint requires: `Authorization: Bearer <token>`.
- `POST /api/auth/logout` → revokes the current token (`revoked_at` set); it is rejected by `AuthMiddleware` on every subsequent request.

No `session_start()` or `$_SESSION` anywhere in the codebase — auth state
is entirely token-based and stateless per request.

---

## 5. API Response Standard

Every endpoint replies with the exact envelope required by the spec:

```jsonc
// success
{ "success": true, "message": "Request successful", "data": { } }

// validation error (422)
{ "success": false, "message": "Validation failed", "errors": { "email": ["Invalid email"] } }

// auth failure (401)
{ "success": false, "message": "Unauthenticated" }
```

`App\Core\Response` is the single place that builds this envelope, and
`App\Core\Router` maps every exception type to the right HTTP status code
(`ValidationException` → 422, `AuthenticationException` → 401,
`AuthorizationException` → 403, `NotFoundException` → 404,
`ConflictException` → 409).

---

## 6. Endpoints

See `postman/ChatApp.postman_collection.json` for a ready-to-run
collection with every endpoint, example payloads, and pre-wired
negative/edge-case requests. Summary:

| Module | Method | Endpoint |
| --- | --- | --- |
| Auth | POST | `/api/auth/signup` |
| Auth | POST | `/api/auth/login` |
| Auth | POST | `/api/auth/logout` |
| Auth | POST / GET | `/api/auth/verify-email` |
| Auth | POST | `/api/auth/resend-verification` |
| User | GET | `/api/me` |
| Company | GET | `/api/company` |
| Company | GET | `/api/company/users` |
| Team | POST / GET | `/api/teams` |
| Team | GET / PUT / DELETE | `/api/teams/{teamId}` |
| Team | GET / POST | `/api/teams/{teamId}/members` |
| Team | DELETE | `/api/teams/{teamId}/members/{userId}` |
| Channel | POST / GET | `/api/teams/{teamId}/channels` |
| Channel | GET / PUT / DELETE | `/api/channels/{channelId}` |
| Channel | GET / POST | `/api/channels/{channelId}/members` |
| Channel | DELETE | `/api/channels/{channelId}/members/{userId}` |
| Message | POST / GET | `/api/channels/{channelId}/messages` |
| Message | GET / PUT / DELETE | `/api/messages/{messageId}` |

All list endpoints for messages support `?limit=&skip=` pagination
(`limit` capped at 200).

---

## 7. Business rule assumptions

The brief pins down most rules precisely, but leaves a few permission
edges to the implementer (explicitly: *"Trainees may use slightly
different REST naming as long as the behavior and authorization rules
remain correct"*). This project resolves them as follows:

- **Team update/delete**: only the team's creator (the field
  `teams.created_by`).
- **Adding a member to a team**: any existing team member may add another
  user, provided the target belongs to the same company (mirrors the
  explicit channel rule in section 10, applied consistently to teams).
- **Removing a team member**: the team creator can remove anyone; a
  member can remove themself.
- **Channel update/delete**: only the channel's creator, per section 10 ("*channel creator can remove any channel member*" extended consistently to update/delete).
- **Editing/deleting a message**: only the message's own sender (`messages.sender_id`). Channel creators do not get blanket edit/delete rights over other members' messages — only over channel membership itself.
- **Signup password policy**: minimum 8 characters, at least one letter and one number (`Validator`'s `password` rule).
- **Duplicate names**: a company cannot have two teams with the same name, and a team cannot have two channels with the same name (`409 Conflict`).

All of the above are enforced in the `Services/` layer, not in
controllers, and every cross-company / cross-team / cross-channel access
attempt returns a `404` (not a `403`) when the resource simply isn't
visible to the requester's company — this avoids confirming that a
resource exists in a company the caller has no access to, while
`403 Forbidden` is reserved for "you can see it exists, but you may not
act on it" cases (e.g. adding a non-team-member to a channel).

---

## 8. MongoDB collections & indexes

| Collection | Purpose |
| --- | --- |
| `users` | name, email, password_hash, company_id, email_verified_at, status |
| `companies` | name, created_by |
| `teams` | name, company_id, created_by |
| `team_members` | team_id, user_id, added_by |
| `channels` | name, team_id, created_by |
| `channel_members` | channel_id, user_id, added_by |
| `messages` | channel_id, sender_id, message, timestamps, deleted_at (soft delete) |
| `auth_tokens` | user_id, token_hash (SHA-256), expires_at, revoked_at |
| `email_verification_tokens` | user_id, token_hash, expires_at, used_at |

Run `php database/create_indexes.php` to create all indexes required by
section 14 of the spec, including the unique compound indexes on
`team_members(team_id, user_id)` and `channel_members(channel_id,
user_id)` that make duplicate memberships impossible even under a race.

---

## 9. Security notes

- Passwords hashed with `password_hash()` (bcrypt); never returned by any endpoint (`Presenter::user()` strips `password_hash`).
- Auth tokens and email verification tokens are stored as SHA-256 hashes only — the raw token exists exactly once, in the response body / email, at issuance time.
- Every protected route resolves `Authorization: Bearer <token>` through `AuthMiddleware`; a missing header, malformed header, expired token, or revoked token all produce a `401`.
- All MongoDB `_id` lookups from client-supplied IDs are validated with `ObjectId::isValid()` before use — malformed IDs return `404`/`422` rather than throwing.
- No secrets are hard-coded; everything sensitive comes from `.env` (see `.env.example`), which is gitignored.

---

## 10. Testing

### Automated (no MongoDB required)
```bash
php tests/smoke_test.php
```
Exercises the router (param extraction, 404/405 handling) and the
validator (required/email/password rules) without needing a live
database.

### End-to-end (requires MongoDB running)
Import `postman/ChatApp.postman_collection.json` into Postman, set
`base_url`, and run the collection top to bottom. It walks through the
exact flow required by section 20 of the spec:

1. Signup User A / Company A → verify email → login → token captured.
2. Confirm **General Team** + **Announcements** channel exist.
3. Create a second team in Company A.
4. Signup User B / Company B, and confirm cross-company operations are rejected (404/403 requests are included).
5. Add an eligible Company A user to the new team; create a channel in it; add another eligible member to the channel; attempt to add a non-team-member (rejected).
6. Send/read messages; attempt without channel membership (rejected).
7. Remove a channel member as the creator.
8. Logout User A and confirm the old token no longer works.

---

## 11. What's intentionally out of scope

Per section 25 of the brief, this is a backend-only assessment: there is
no frontend, no HTML views, and no session-based auth anywhere in the
codebase.
