# 🔑 Test Credentials & Multi-Tenant Directory

This document contains standardized login credentials, branch mappings, and multi-tenant setup data for QA testing across **Tenant 1** and **Tenant 2**.

---

### 🗝️ Universal Test Password
> **Password for ALL Accounts**: `12345678`

---

## 🏢 Tenant 1 — "عيادة النور التخصصية"

- **Tenant Identifier**: `tenant-1`
- **Domain**: `clinic1.my-saas.test`
- **Branch Configuration**: Single Branch (`الفرع الرئيسي` / Main Branch)

### 👥 Accounts & Credentials

| Role | Display Name | Email Address | Universal Password | Assigned Branch(es) |
| :--- | :--- | :--- | :---: | :--- |
| **Doctor 1** | د. أحمد علي | `dr.ahmed@tenant1.com` | `12345678` | الفرع الرئيسي |
| **Doctor 2** | د. سارة محمود | `dr.sara@tenant1.com` | `12345678` | الفرع الرئيسي |
| **Shared Receptionist** | استقبال الفرع الرئيسي | `reception@tenant1.com` | `12345678` | الفرع الرئيسي |

---

## 🏢 Tenant 2 — "مجموعة عيادات الأمل الطبية"

- **Tenant Identifier**: `tenant-2`
- **Domain**: `clinic2.my-saas.test`
- **Branch Configuration**: Multi-Branch (`فرع مدينة نصر` & `فرع التجمع الخامس`)

### 👥 Accounts & Credentials

| Role | Display Name | Email Address | Universal Password | Assigned Branch(es) |
| :--- | :--- | :--- | :---: | :--- |
| **Shared Doctor** | د. طارق خليل | `dr.tarek@tenant2.com` | `12345678` | فرع مدينة نصر + فرع التجمع الخامس |
| **Receptionist (Branch A)** | استقبال فرع مدينة نصر | `reception.nasr@tenant2.com` | `12345678` | فرع مدينة نصر |
| **Receptionist (Branch B)** | استقبال فرع التجمع | `reception.tagamoa@tenant2.com` | `12345678` | فرع التجمع الخامس |

---

## 🛠️ Seeding Command Reference

To execute or re-seed multi-tenant user accounts at any time:

```bash
# Seed multi-tenant users and branch structures
php artisan db:seed --class=UserTenantSeeder

# Fresh database migration with full automated seeders
php artisan migrate:fresh --seed
```
