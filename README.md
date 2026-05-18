# Full-Stack Azure EntraID Auth Demo

Spring Boot 4.0.3 (Java 21) backend + React/Vite frontend, secured with Azure EntraID (MSAL).

---

## Local Development

### Backend

```bash
cd backend
# Set environment variables (or export them in your shell)
export DB_USER=root
export DB_PASSWORD=yourpassword
export AZURE_TENANT_ID=<your-tenant-id>
export AZURE_CLIENT_ID=<your-backend-client-id>

mvn spring-boot:run
# Swagger UI: http://localhost:8080/swagger-ui.html
```

### Frontend

```bash
cd frontend
npm install
# Edit .env.local with your Azure values (see .env.local)
npm run dev
# App: http://localhost:5173
```

---

## Docker Compose

```bash
# 1. Copy and fill in the root .env file
cp .env .env.local   # then edit values

# 2. Build and start all services
docker compose up --build

# Frontend → http://localhost:80
# Backend  → http://localhost:8080
# Swagger  → http://localhost:8080/swagger-ui.html
```

---

## Azure Portal Configuration

### Step 1 — App Registration for the Backend (API)

1. Go to **Azure Portal → Entra ID → App registrations → New registration**
2. Name: `articles-api` (single tenant)
3. Under **Expose an API**:
   - Set Application ID URI: `api://<backend-client-id>`
   - Add scope: `access_as_user` (Admins and users)
4. Under **App roles → Create app role**:
   - Name: `EDITOR`, Value: `EDITOR`, Allowed member types: `Users/Groups`
   - Name: `PARTICIPANT`, Value: `PARTICIPANT`, Allowed member types: `Users/Groups`
5. Copy the **Application (client) ID** and **Directory (tenant) ID** → use as `AZURE_CLIENT_ID` / `AZURE_TENANT_ID`

### Step 2 — App Registration for the Frontend (SPA)

1. **Azure Portal → Entra ID → App registrations → New registration**
2. Name: `articles-frontend`
3. Under **Authentication → Add a platform → Single-page application**:
   - Redirect URI: `http://localhost:5173`
   - Enable **Access tokens** and **ID tokens**
4. Under **API permissions → Add a permission → My APIs → articles-api**:
   - Add delegated permission: `access_as_user`
   - Click **Grant admin consent**
5. Copy the **Application (client) ID** → use as `VITE_AZURE_CLIENT_ID`

### Step 3 — Assign Roles to Users

1. **Azure Portal → Entra ID → Enterprise applications → articles-api**
2. Go to **Users and groups → Add user/group**
3. Select a user and assign them the `EDITOR` or `PARTICIPANT` role

> Roles appear in the access token's `roles` claim after the next sign-in.

---

## API Reference

| Method | Endpoint              | Auth required         |
|--------|-----------------------|-----------------------|
| GET    | `/api/articles`       | Public                |
| GET    | `/api/articles/{id}`  | Public                |
| POST   | `/api/articles`       | `EDITOR`              |
| PUT    | `/api/articles/{id}`  | `EDITOR`              |
| DELETE | `/api/articles/{id}`  | `EDITOR`              |
| POST   | `/api/comments`       | `EDITOR` or `PARTICIPANT` |
| PUT    | `/api/comments/{id}`  | `EDITOR` or own comment |
| DELETE | `/api/comments/{id}`  | `EDITOR` or own comment |

All responses follow `ApiResponse<T>`:
```json
{ "success": true, "message": "...", "data": { ... } }
```
