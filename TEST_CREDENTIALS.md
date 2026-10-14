# 🔑 بيانات الدخول وروابط الاختبار (Test Credentials & Multi-Tenant Directory)

دليل شامل ومحدث لبيانات الدخول، معرفات الفروع (Branch UUIDs)، وشاشات صالة الانتظار لاختبار النظام كاملًا عبر معمارية **Database-per-Tenant** المعزولة، مع معمارية العيادة الموحدة ونظام الأدوار (`clinic_owner`, `doctor`, `receptionist`) وفق بيئات العمل الثلاث المعتمدة:

---

> [!IMPORTANT]
> **تأكد من تشغيل السيرفرات الأساسية في الـ Terminals:**
>
> 1. **الباك إند (Laravel API)**:
>    ```bash
>    cd ServerSide
>    php artisan serve --host=0.0.0.0 --port=8000
>    ```
> 2. **الفرونت إند (React + Vite)**:
>    ```bash
>    cd ClientSide
>    npm run dev
>    ```
> 3. **خادم الـ WebSockets (Laravel Reverb)**:
>    ```bash
>    cd ServerSide
>    php artisan reverb:start
>    ```

---

### 🗝️ كلمة المرور الموحدة لجميع الحسابات التجريبية

> **Password for ALL Accounts**: `12345678`

---

## 👑 0. لوحة تحكم المنصة المركزية (Central Platform Super Admin)

- **الرابط المباشر**: [http://localhost:5173/login](http://localhost:5173/login)
- **مسار لوحة التحكم**: `/platform` (أو [http://localhost:5173/platform](http://localhost:5173/platform))
- **البريد الإلكتروني**: `admin@platform.test`
- **كلمة المرور**: `12345678`
- **الصلاحيات**: إدارة كافة العيادات والمستأجرين، تفعيل/تعطيل العيادات، تجهيز العيادات، والدخول كمالك عيادة (Impersonation).

---

## 🏥 1. العيادة (أ): مجموعة عيادات النور (Clinic A — Polyclinic Multi-Branch)

- **معرف المستأجر (Tenant ID)**: `tenant-1`
- **قاعدة البيانات المعزولة**: `tenant_tenant-1`
- **روابط الوصول**:
  - رابط المتصفح المحلي: [http://clinic1.localhost:5173](http://clinic1.localhost:5173)
  - روابط النطاق المخصص: [http://clinic1.my-saas.test:5173](http://clinic1.my-saas.test:5173) أو [http://al-noor.my-saas.test:5173](http://al-noor.my-saas.test:5173)
- **سياق التشغيل**: عيادة متعددة الفروع؛ طابور حي وصالة انتظار خاصة بكل فرع، وجدول مواعيد واستقبال منفصلين.

### 🏢 الفروع ومعرفاتها (Branch IDs):

| الفرع | معرف الفرع (UUID) | رابط شاشة صالة الانتظار (Live Queue TV) |
| :--- | :--- | :--- |
| **Branch 1 - فرع المعادي** | `01a13a38-c4cf-71d7-99e2-00533a621fcc` | [شاشة انتظار فرع المعادي](http://clinic1.localhost:5173/waiting-room?branch_id=01a13a38-c4cf-71d7-99e2-00533a621fcc) |
| **Branch 2 - فرع مدينة نصر** | `01a13a38-c4d7-738d-b758-341b34b02643` | [شاشة انتظار مدينة نصر](http://clinic1.localhost:5173/waiting-room?branch_id=01a13a38-c4d7-738d-b758-341b34b02643) |

### 👥 حسابات الطاقم الطبي والإداري:

| الدور | الاسم | البريد الإلكتروني | كلمة المرور | الفروع المعينة |
| :--- | :--- | :--- | :---: | :--- |
| **طبيب مشترك (Cross-Branch)** | د. أحمد علي (Dr. Ahmed) | `dr.ahmed@clinica.test`<br>_(بديل: `dr.ahmed@alnoor.com`)_ | `12345678` | Branch 1 + Branch 2 (`clinic_owner`, `doctor`) |
| **موظف استقبال فرع 1** | سارة - استقبال المعادي | `reception.branch1@clinica.test` | `12345678` | Branch 1 (المعادي فقط) (`receptionist`) |
| **موظف استقبال فرع 2** | منى - استقبال مدينة نصر | `reception.branch2@clinica.test`<br>_(بديل: `reception.mona@alnoor.com`)_ | `12345678` | Branch 2 (مدينة نصر فقط) (`receptionist`) |

---

## 🏥 2. العيادة (ب): مستشفى الأمل التخصصي (Clinic B — Dual Doctor Shared Reception)

- **معرف المستأجر (Tenant ID)**: `tenant-2`
- **قاعدة البيانات المعزولة**: `tenant_tenant-2`
- **روابط الوصول**:
  - رابط المتصفح المحلي: [http://clinic2.localhost:5173](http://clinic2.localhost:5173)
  - روابط النطاق المخصص: [http://clinic2.my-saas.test:5173](http://clinic2.my-saas.test:5173) أو [http://al-amal.my-saas.test:5173](http://al-amal.my-saas.test:5173)
- **سياق التشغيل**: طبيبان يعمل كل منهما حصرياً في جناحه/فرعه مع عزل كامل للبيانات (IDOR protection)، وموظفة استقبال مشتركة توزع المرضى وتدير طابور وحسابات الجناحين.

### 🏢 الأجنحة والفروع ومعرفاتها (Branch IDs):

| الفرع / الجناح | معرف الفرع (UUID) | رابط شاشة صالة الانتظار (Live Queue TV) |
| :--- | :--- | :--- |
| **East Wing - الجناح الشرقي** | `01a13a38-dfd7-7257-a022-2a50d41fcd18` | [شاشة انتظار الجناح الشرقي](http://clinic2.localhost:5173/waiting-room?branch_id=01a13a38-dfd7-7257-a022-2a50d41fcd18) |
| **West Wing - الجناح الغربي** | `01a13a38-dfdc-735f-8455-5fa42bb176db` | [شاشة انتظار الجناح الغربي](http://clinic2.localhost:5173/waiting-room?branch_id=01a13a38-dfdc-735f-8455-5fa42bb176db) |

### 👥 حسابات الطاقم الطبي والإداري:

| الدور | الاسم | البريد الإلكتروني | كلمة المرور | الفروع المعينة |
| :--- | :--- | :--- | :---: | :--- |
| **طبيب الجناح الشرقي** | د. طارق خليل (Dr. Tarek) | `dr.tarek@clinicb.test`<br>_(بديل: `dr.tarek@tenant2.com`)_ | `12345678` | East Wing فقط (`clinic_owner`, `doctor`) |
| **طبيب الجناح الغربي** | د. خالد عبد الرحمن (Dr. Khaled) | `dr.khaled@clinicb.test`<br>_(بديل: `dr.khaled@tenant2.com`)_ | `12345678` | West Wing فقط (`doctor`) |
| **استقبال مشترك (Shared)** | هدى - استقبال مشترك | `reception.shared@clinicb.test`<br>_(بديل: `reception.hoda@alamal.com`)_ | `12345678` | East Wing + West Wing (`receptionist`) |

---

## 🏥 3. العيادة (ج): عيادة الشيخ زايد (Clinic C — Zayed Branch)

- **معرف المستأجر (Tenant ID)**: `tenant-3`
- **قاعدة البيانات المعزولة**: `tenant_tenant-3`
- **روابط الوصول**:
  - رابط المتصفح المحلي: [http://clinic3.localhost:5173](http://clinic3.localhost:5173)
  - روابط النطاق المخصص: [http://clinic3.my-saas.test:5173](http://clinic3.my-saas.test:5173)
- **سياق التشغيل**: عيادة بمعمارية موحدة متكاملة؛ تضم مكتب استقبال لتسجيل الحضور وإدارة الطابور وتحصيل الفواتير، وغرفة كشف للطبيب لإجراء المعاينة، تسجيل التشخيص والأدوية، والقياسات الحيوية.

### 🏢 الفرع ومعرفه (Branch ID):

| الفرع | معرف الفرع (UUID) | رابط شاشة صالة الانتظار (Live Queue TV) |
| :--- | :--- | :--- |
| **Main Branch - الفرع الرئيسي** | `01a13a38-faee-7019-af68-f0437e9a77bc` | [شاشة انتظار فرع زايد](http://clinic3.localhost:5173/waiting-room?branch_id=01a13a38-faee-7019-af68-f0437e9a77bc) |

### 👥 حسابات الطاقم الطبي والإداري:

| الدور | الاسم | البريد الإلكتروني | كلمة المرور | الفروع المعينة |
| :--- | :--- | :--- | :---: | :--- |
| **طبيب ومالك العيادة** | د. شريف عبد المنعم (Dr. Sherif) | `dr.sherif@clinicc.test`<br>_(بديل: `dr.sherif@solo.test`)_ | `12345678` | Main Branch (`clinic_owner`, `doctor`) |
| **موظفة استقبال زايد** | سارة - استقبال فرع زايد | `reception.zayed@clinicc.test` | `12345678` | Main Branch (`receptionist`) |

---

## 🔄 مسار فحص وتجربة النظام (How to Test Unified Flow)

1. **الاستقبال (Reception):**
   - افتح المتصفح على [http://clinic3.localhost:5173](http://clinic3.localhost:5173) (أو clinic1 أو clinic2).
   - سجل الدخول بحساب موظف الاستقبال (`reception.zayed@clinicc.test`).
   - سجل حضور مريض أو احجز موعداً جديداً -> سيظهر المريض فوراً في الطابور الحي وحالة الانتظار.
2. **شاشة الانتظار (TV Display):**
   - افتح رابط شاشة صالة الانتظار في تبويب منفصل.
   - ستعرض الشاشة الطابور الحي بدون بيانات طبية سرية للمريض وتتحدث فورياً عبر WebSockets.
3. **غرفة الطبيب (Doctor Consultation):**
   - في نافذة متصفح خاصة (Incognito)، ادخل على [http://clinic3.localhost:5173](http://clinic3.localhost:5173).
   - سجل الدخول بحساب الطبيب (`dr.sherif@clinicc.test`).
   - ستنتقل تلقائياً لغرفة الكشف `/doctor`.
   - اضغط **"نداء التالي (Call Next)"** لبدء الكشف، وسجل العلامات الحيوية والتشخيص والروشتة، ثم اضغط **"إنهاء الكشف (Complete)"**.
4. **الخزينة والدفع (Cashier & Billing):**
   - يعود موظف الاستقبال لفتح درج المدفوعات المعلقة (Pending Payments Drawer).
   - تحصيل قيمة الكشف وأي خدمات إضافية كاش أو فيزا أو دفع مقسم -> اكتمال دورة المريض بنجاح.

---

## 🛠️ أوامر إعادة التهيئة وزرع البيانات (Seeding & Reset Commands)

لإعادة مسح وضبط قواعد البيانات وزرع العيادات الثلاث ببياناتها من الصفر في أي وقت:

```bash
# داخل مجلد ServerSide:

# 1. مسح جميع قواعد بيانات المستأجرين القديمة بأمان:
php artisan tenants:wipe-all

# 2. إعادة بناء القاعدة المركزية وقواعد العيادات وزرع الحسابات الافتراضية:
php artisan migrate:fresh --seed
```
