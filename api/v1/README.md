# Isoko Ryacu REST API (v1)

The REST API is decoupled from the website presentation layer. A future Android or cross-platform mobile app can call these endpoints without rebuilding the backend.

## Base URL

```
http://localhost/isoko-ryacu/api/v1/
```

## Authentication

Sessions are used in Phase 1. Future phases will switch to bearer tokens for stateless mobile clients.

| Endpoint | Method | Body | Returns |
|----------|--------|------|---------|
| `/auth/register` | POST | `{full_name, email, password, phone?, location?}` | `201 {user_id, session}` |
| `/auth/login` | POST | `{email, password}` | `200 {user}` or `401 {error}` |
| `/auth/logout` | POST | — | `200 {message}` |

## Resources

### Categories
- `GET /categories` — list top-level categories (`?limit=50`)
- `GET /categories?slug=vehicles` — single category

### Listings
- `GET /listings` — search/filter (`?q=&type=sell|rent&category=&location=&sort=newest|price_asc|price_desc|popular&limit=12`)
- `GET /listings?id=1` — single listing with images + attributes

### Favorites (requires login)
- `GET /favorites` — list current user's favorites
- `POST /favorites` — `{listing_id}` → `201 {message}`

### Rentals (requires login)
- `GET /rentals` — list rental requests sent by user
- `POST /rentals` — `{listing_id, start_date, end_date, message?}` → `201 {id}`

### Users
- `GET /users/me` — current user profile

## Response format

All responses are JSON with `Content-Type: application/json`.

```json
{ "count": 11, "data": [ /* ... */ ] }
```

Errors use `4xx` / `5xx` with `{ "error": "message" }`.

## CORS

`Access-Control-Allow-Origin: *` is set for development. Lock down to your mobile app's domain in production.
