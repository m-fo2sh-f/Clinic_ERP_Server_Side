# 🔍 COMPREHENSIVE CODEBASE SECURITY & ARCHITECTURE AUDIT REPORT
**Project:** Healios Multi-Tenant Clinic ERP (Laravel Sanctum + React)  
**Date:** August 2, 2026  
**Audit Target:** `ServerSide` (Laravel Backend) & `ClientSide` (React Frontend)  

---

## 1. 📂 FULL PROJECT FOLDER STRUCTURE

### 🖥️ Backend Directory (`ServerSide/`)
```
ServerSide/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Controller.php
│   │   │   └── Api/
│   │   │       ├── AuthController.php            [🔑 Sanctum Auth: Login, Logout, /me]
│   │   │       └── V1/
│   │   │           ├── AppointmentController.php [📅 Appointments CRUD & Check-in]
│   │   │           ├── LiveQueueController.php   [🏥 Live Queue management & calling next]
│   │   │           └── PatientController.php     [👨‍⚕️ Patient Search & Medical History]
│   │   └── Middleware/
│   │       └── SetBranchContext.php             [🏢 Reads X-Branch-ID header & sets app scope]
│   ├── Models/
│   │   ├── User.php                             [👤 User model with HasApiTokens & HasRoles]
│   │   ├── Branch.php                           [🏢 Clinic Branch model]
│   │   ├── Tenant.php                           [🌐 Stancl Tenancy Tenant model]
│   │   ├── Patient.php                          [🩺 Patient records model]
│   │   ├── Appointment.php                      [📆 Appointments model]
│   │   ├── LiveQueue.php                        [⏱️ Live queue items model]
│   │   └── ClinicSetting.php                    [⚙️ Branch clinic configuration model]
│   └── Providers/
│       ├── AppServiceProvider.php               [⚙️ App bindings]
│       └── TenancyServiceProvider.php           [🌐 Multi-tenancy event & route mapper]
├── bootstrap/
│   └── app.php                                  [🚀 Laravel 11 routing, middleware & exceptions config]
├── config/
│   ├── app.php                                  [⚙️ General application config]
│   ├── auth.php                                 [🔒 Auth guards (web, sanctum) & providers]
│   ├── cors.php                                 [🌐 CORS allowed origins & headers]
│   ├── sanctum.php                              [🍪 Sanctum SPA stateful domains config]
│   ├── session.php                              [🍪 Cookie & domain session config]
│   └── tenancy.php                              [🌐 Stancl Tenancy configuration]
├── database/
│   ├── migrations/                              [📊 System & tenant migrations]
│   └── seeders/
│       ├── DatabaseSeeder.php                   [🌱 Root seeder creating tenants, branches & users]
│       └── RolesAndUsersSeeder.php              [🌱 Spatie roles & permissions seeder]
└── routes/
    ├── channels.php                             [📡 Reverb WebSocket channels]
    ├── console.php                              [💻 Artisan console commands]
    ├── tenant.php                               [🌐 Multi-tenant API & Auth routes]
    └── web.php                                  [🌐 Central domain web routes]
```

### 💻 Frontend Directory (`ClientSide/`)
```
ClientSide/
├── public/                                      [🖼️ Static assets]
├── src/
│   ├── assets/                                  [🎨 Stylesheets & icons]
│   ├── components/
│   │   ├── queue/                               [🏥 Queue UI: LiveQueue, BookingList, QuickActions]
│   │   └── ui/                                  [🧩 Atomic UI: Select, Badge, Button, Card, Dialog, BranchSelectionModal]
│   ├── context/
│   │   └── BranchContext.jsx                    [🏬 React context for active branch & user auth state]
│   ├── hooks/
│   │   ├── useAppointments.js                   [⚡ React Query hooks for appointments]
│   │   ├── useQueue.js                          [⚡ React Query hooks for live queue]
│   │   └── useQueueWebSocket.js                 [📡 Reverb WebSocket subscription hook]
│   ├── layouts/
│   │   └── DashboardLayout.jsx                  [📐 Main layout with sidebar, header & branch switcher]
│   ├── pages/
│   │   ├── DoctorDashboard.jsx                  [👨‍⚕️ Doctor examination workspace]
│   │   ├── ReceptionistDashboard.jsx            [📋 Receptionist desk workspace]
│   │   ├── WaitingRoomDisplay.jsx               [📺 TV waiting room display page]
│   │   └── LoginPage.jsx                        [🔑 Authentication page with multi-branch modal]
│   ├── services/
│   │   ├── api.js                               [🌐 Axios instance with withCredentials & X-Branch-ID interceptor]
│   │   ├── authService.js                       [🔑 Sanctum CSRF handshake & Auth API calls]
│   │   └── echo.js                              [📡 Laravel Echo / Reverb WebSocket client]
│   ├── App.jsx                                  [🚦 React Router & QueryClient root configuration]
│   └── main.jsx                                 [⚛️ React application entry point]
├── package.json                                 [📦 Node dependencies]
└── vite.config.js                               [⚡ Vite bundler configuration]
```

---

## 2. 🔐 AUTHENTICATION & SANCTUM AUDIT (HIGH PRIORITY)

| Component | Status | Finding / Flaw | Risk & Impact |
| :--- | :---: | :--- | :--- |
| **Sanctum Package** | ✅ Configured | `laravel/sanctum` installed and `HasApiTokens` applied to `User` model. | Prevents `Auth guard [sanctum] is not defined` errors. |
| **`SANCTUM_STATEFUL_DOMAINS`** | ⚠️ Needs Update | Listed as `SANCTUM_STATEFUL_DOMAINS=my-saas.test:5173,clinic1.my-saas.test:5173` in `.env`. Wildcard subdomains (e.g. `clinic2`, `clinic3`) will fail CSRF verification. | **High Impact**: Requests from unlisted tenant subdomains will fail with `419 CSRF Token Mismatch`. |
| **`SESSION_DOMAIN`** | ✅ Correct | Set to `SESSION_DOMAIN=".my-saas.test"` in `.env`, allowing session cookies to share across all subdomains. | Crucial for multi-tenant subdomain auth. |
| **Axios `withCredentials`** | ✅ Enabled | `src/services/api.js` explicitly sets `withCredentials: true`. | Required for passing HttpOnly session & CSRF cookies. |
| **CSRF Handshake** | ✅ Working | `authService.js` calls `getCsrfCookie()` before `/login`. Excluded in `bootstrap/app.php` for seamless SPA authentication. | Prevents 419 errors during login. |
| **Frontend Token Hardcoding** | ⚠️ Cleanup | `DashboardLayout.jsx` contains static mock user token references (`mockUser.token`). | **Low Impact**: Cosmetic UI code left over from static mocks. |

---

## 3. 👥 ROLES & PERMISSIONS AUDIT (Spatie Package)

| Area | Status | Finding / Flaw | Risk & Impact |
| :--- | :---: | :--- | :--- |
| **User Model** | ✅ Correct | `Spatie\Permission\Traits\HasRoles` added to `User` model. | Enables Spatie role & permission checks. |
| **API Roles Exposure** | ✅ Returned | `AuthController.php` returns `roles` and `permissions` arrays in `login` and `/me` responses. | Frontend can read permissions dynamically. |
| **Backend API Route Authorization** | 🛑 CRITICAL | Routes in `routes/tenant.php` (`/appointments`, `/live-queues`, `/patients`) have **NO** Spatie `role:` or `permission:` middleware attached. | **CRITICAL SECURITY RISK**: Any authenticated user (e.g., receptionist) can directly hit doctor endpoints like `POST /live-queues/next` or update patient medical histories without authorization checks. |
| **Frontend Route Guards** | 🛑 CRITICAL | Routes in `App.jsx` (`/`, `/dashboard`, `/doctor`) are **unprotected**. Navigating directly to `/doctor` in the browser bypasses role checks. | **CRITICAL RISK**: Unauthenticated or unauthorized users can open the Doctor Dashboard interface. |

---

## 4. 🏢 MULTI-BRANCH CONTEXT & HEADER ISOLATION (IDOR PROTECTION)

| Checkpoint | Status | Finding / Flaw | Risk & Impact |
| :--- | :---: | :--- | :--- |
| **Axios Interceptor** | ✅ Active | `api.js` automatically attaches `X-Branch-ID` header from `localStorage.getItem('active_branch_id')`. | Ensures every request includes active branch context. |
| **Middleware Context Setting** | ✅ Active | `SetBranchContext` reads `X-Branch-ID` and sets `app('active_branch_id')`. | Sets application-wide branch scope. |
| **Backend IDOR Validation** | 🛑 CRITICAL | `SetBranchContext` middleware currently accepts **ANY** `X-Branch-ID` passed in the header without verifying if the user belongs to that branch. | **CRITICAL IDOR VULNERABILITY**: User A in Branch 1 can manually send `X-Branch-ID: branch_2_id` in headers to read/modify Branch 2 data. |
| **Stale LocalStorage UUIDs** | ✅ Fixed | `BranchContext.jsx` queries `/api/me` on mount and clears invalid/stale branch IDs. | Prevents `The selected branch id is invalid` 422 errors. |

---

## 5. 📋 ITEMIZED FIX LIST (STEP-BY-STEP REVIEW PLAN)

### 🛑 CRITICAL PRIORITY (Security & Access Control)

#### Issue 1: Missing Backend IDOR Branch Ownership Check
- **Location:** `ServerSide/app/Http/Middleware/SetBranchContext.php`
- **Why it matters:** An attacker could tamper with the `X-Branch-ID` header to query or alter data belonging to a branch they do not belong to.
- **Proposed Code Fix:**
```php
public function handle(Request $request, Closure $next): Response
{
    $branchId = $request->header('X-Branch-ID');
    $user = $request->user();

    if ($branchId) {
        // IDOR Check: Ensure authenticated user owns/belongs to requested branch
        if ($user && method_exists($user, 'branches')) {
            $hasAccess = $user->branches()->where('branches.id', $branchId)->exists();
            if (!$hasAccess) {
                return response()->json(['message' => 'Unauthorized access to requested branch.'], 403);
            }
        }

        app()->instance('active_branch_id', $branchId);
        config(['app.active_branch_id' => $branchId]);
    }

    return $next($request);
}
```

---

#### Issue 2: Missing Frontend Route Authorization & Protection
- **Location:** `ClientSide/src/components/ProtectedRoute.jsx` & `ClientSide/src/App.jsx`
- **Why it matters:** Users can directly visit `/doctor` or `/dashboard` without logging in or having the appropriate Spatie role (`doctor`, `receptionist`).
- **Proposed Code Fix:**
1. Create `ClientSide/src/components/ProtectedRoute.jsx`:
```jsx
import React from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useBranchContext } from '../context/BranchContext';

export const ProtectedRoute = ({ children, allowedRoles = [] }) => {
  const { user } = useBranchContext();
  const location = useLocation();

  if (!user) {
    return <Navigate to="/login" state={{ from: location }} replace />;
  }

  if (allowedRoles.length > 0) {
    const userRoles = user.roles || [];
    const hasRole = allowedRoles.some(role => userRoles.includes(role));
    if (!hasRole) {
      return <div className="p-8 text-center text-red-600 font-bold">403 - Forbidden: Role Required</div>;
    }
  }

  return children;
};
```
2. Update `ClientSide/src/App.jsx`:
```jsx
<Routes>
  <Route path="/login" element={<LoginPage />} />
  <Route path="/dashboard" element={
    <ProtectedRoute allowedRoles={['receptionist', 'tenant_admin']}>
      <DashboardLayout><ReceptionistDashboard /></DashboardLayout>
    </ProtectedRoute>
  } />
  <Route path="/doctor" element={
    <ProtectedRoute allowedRoles={['doctor', 'tenant_admin']}>
      <DashboardLayout><DoctorDashboard /></DashboardLayout>
    </ProtectedRoute>
  } />
  <Route path="/waiting-room" element={<WaitingRoomDisplay />} />
</Routes>
```

---

### ⚠️ MEDIUM PRIORITY (Configuration & Domain Matching)

#### Issue 3: Incomplete `SANCTUM_STATEFUL_DOMAINS` for Subdomains
- **Location:** `ServerSide/.env` & `ServerSide/config/sanctum.php`
- **Why it matters:** Subdomains dynamically created for new tenants will fail CORS / CSRF verification if not matched.
- **Proposed Code Fix in `.env`:**
```env
SANCTUM_STATEFUL_DOMAINS=my-saas.test,clinic1.my-saas.test,clinic2.my-saas.test,my-saas.test:5173,clinic1.my-saas.test:5173,clinic2.my-saas.test:5173,localhost:5173
```

---

### 💡 LOW PRIORITY (Code Cleanup & UX Polish)

#### Issue 4: Legacy Token Hardcoding in Header UI
- **Location:** `ClientSide/src/layouts/DashboardLayout.jsx`
- **Why it matters:** Static reference to `mockUser.token` displays obsolete mock tokens in the sidebar footer.
- **Proposed Fix:** Display `activeBranch.name` or session state instead of static token string.
