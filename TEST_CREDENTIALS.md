# 🔑 Test Credentials & Multi-Tenant Directory

This document contains standardized login credentials, branch mappings, waiting room TV screen links, and multi-tenant setup data for QA testing across **Tenant 1** and **Tenant 2**.

---

### 🗝️ Universal Test Password
> **Password for ALL Accounts**: `12345678`

---

## 🏢 Tenant 1 — "عيادة النور التخصصية"

- **Tenant Identifier**: `tenant-1`
- **Domain**: `clinic1.my-saas.test`
- **Branch Configuration**: Single Branch (`الفرع الرئيسي` / Main Branch)
- **Branch ID**: `01a077d0-54a3-701d-ae2f-fba0fede491e`

### 📺 شاشة صالة الانتظار للفرع (TV Waiting Room Display)
- 🌐 **رابط الشاشة عبر الدومين**: [http://clinic1.my-saas.test:5173/waiting-room?branch_id=01a077d0-54a3-701d-ae2f-fba0fede491e](http://clinic1.my-saas.test:5173/waiting-room?branch_id=01a077d0-54a3-701d-ae2f-fba0fede491e)
- 🖥️ **رابط الشاشة عبر Localhost**: [http://localhost:5173/waiting-room?branch_id=01a077d0-54a3-701d-ae2f-fba0fede491e](http://localhost:5173/waiting-room?branch_id=01a077d0-54a3-701d-ae2f-fba0fede491e)

### 👥 Accounts & Credentials

| Role | Display Name | Email Address | Password | Assigned Branch |
| :--- | :--- | :--- | :---: | :--- |
| **Doctor 1** | د. أحمد علي | `dr.ahmed@tenant1.com` | `12345678` | الفرع الرئيسي |
| **Doctor 2** | د. سارة محمود | `dr.sara@tenant1.com` | `12345678` | الفرع الرئيسي |
| **Receptionist** | استقبال الفرع الرئيسي | `reception@tenant1.com` | `12345678` | الفرع الرئيسي |

---

## 🏢 Tenant 2 — "مجموعة عيادات الأمل الطبية"

- **Tenant Identifier**: `tenant-2`
- **Domain**: `clinic2.my-saas.test`
- **Branch Configuration**: Multi-Branch (`فرع مدينة نصر` & `فرع التجمع الخامس`)

### 👥 Accounts, Branches & Waiting Room Screens

#### 📍 فرع مدينة نصر (Branch A)
- **Branch ID**: `01a077d0-54c9-70bd-b963-8066d8f64a77`
- 📺 **رابط شاشة الانتظار عبر الدومين**: [http://clinic2.my-saas.test:5173/waiting-room?branch_id=01a077d0-54c9-70bd-b963-8066d8f64a77](http://clinic2.my-saas.test:5173/waiting-room?branch_id=01a077d0-54c9-70bd-b963-8066d8f64a77)
- 🖥️ **رابط شاشة الانتظار عبر Localhost**: [http://localhost:5173/waiting-room?branch_id=01a077d0-54c9-70bd-b963-8066d8f64a77](http://localhost:5173/waiting-room?branch_id=01a077d0-54c9-70bd-b963-8066d8f64a77)

| Role | Display Name | Email Address | Password | Assigned Branch |
| :--- | :--- | :--- | :---: | :--- |
| **Doctor A** | د. طارق خليل | `dr.tarek@tenant2.com` | `12345678` | فرع مدينة نصر |
| **Receptionist A** | استقبال فرع مدينة نصر | `reception.nasr@tenant2.com` | `12345678` | فرع مدينة نصر |

---

#### 📍 فرع التجمع الخامس (Branch B)
- **Branch ID**: `01a077d0-54cb-7019-999f-8210f64b8294`
- 📺 **رابط شاشة الانتظار عبر الدومين**: [http://clinic2.my-saas.test:5173/waiting-room?branch_id=01a077d0-54cb-7019-999f-8210f64b8294](http://clinic2.my-saas.test:5173/waiting-room?branch_id=01a077d0-54cb-7019-999f-8210f64b8294)
- 🖥️ **رابط شاشة الانتظار عبر Localhost**: [http://localhost:5173/waiting-room?branch_id=01a077d0-54cb-7019-999f-8210f64b8294](http://localhost:5173/waiting-room?branch_id=01a077d0-54cb-7019-999f-8210f64b8294)

| Role | Display Name | Email Address | Password | Assigned Branch |
| :--- | :--- | :--- | :---: | :--- |
| **Doctor B** | د. خالد عبد الرحمن | `dr.khaled@tenant2.com` | `12345678` | فرع التجمع الخامس |
| **Receptionist B** | استقبال فرع التجمع | `reception.tagamoa@tenant2.com` | `12345678` | فرع التجمع الخامس |

---

## 🛠️ Seeding Command Reference

To re-seed multi-tenant user accounts at any time:

```bash
# Seed multi-tenant users and branch structures
php artisan db:seed --class=UserTenantSeeder

# Fresh database migration with full automated seeders
php artisan migrate:fresh --seed
```
