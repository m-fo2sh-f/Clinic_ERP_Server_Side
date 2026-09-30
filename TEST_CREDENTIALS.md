# 🔑 بيانات الدخول وروابط الاختبار (Test Credentials & Multi-Tenant Directory)

دليل شامل ومحدث لبيانات الدخول، معرفات الفروع (Branch UUIDs)، وشاشات الانتظار لاختبار النظام كاملًا عبر معمارية **Database-per-Tenant** ونظام الأدوار المبسط (`clinic_owner`, `doctor`, `receptionist`) وفق بيئات العمل الثلاث المعتمدة:

---

> [!IMPORTANT]
> **تأكد من تشغيل السيرفرات الأساسية في الـ Terminals:**
> 1. **الباك إند (Laravel API)**:
>    ```bash
>    php artisan serve --host=0.0.0.0 --port=8000
>    ```
> 2. **الفرونت إند (React + Vite)**:
>    ```bash
>    npm run dev
>    ```
> 3. **خادم الـ WebSockets (Laravel Reverb)**:
>    ```bash
>    php artisan reverb:start
>    ```

---

### 🗝️ كلمة المرور الموحدة لجميع الحسابات التجريبية
> **Password for ALL Accounts**: `12345678`

---

## 👑 0. لوحة تحكم المنصة المركزية (Central Platform Super Admin)

* **الرابط المباشر**: [http://localhost:5173/login](http://localhost:5173/login)
* **مسار لوحة التحكم**: `/platform` (أو [http://localhost:5173/platform](http://localhost:5173/platform))
* **البريد الإلكتروني**: `admin@platform.test`
* **كلمة المرور**: `12345678`
* **الصلاحيات**: إدارة كافة العيادات والمستأجرين، تفعيل/تعطيل العيادات، التجهيز الآلي (Provisioning)، والدخول كمالك عيادة (Impersonation).

---

## 🏥 1. العيادة (أ): مجموعة عيادات النور (Clinic A — Polyclinic Multi-Branch)

* **معرف المستأجر (Tenant ID)**: `tenant-1`
* **نمط التشغيل (Clinic Mode)**: `polyclinic`
* **قاعدة البيانات المعزولة**: `tenant_tenant-1`
* **روابط الوصول**:
  * رابط المتصفح المحلي: [http://clinic1.localhost:5173](http://clinic1.localhost:5173)
  * روابط النطاق المخصص: [http://clinic1.my-saas.test:5173](http://clinic1.my-saas.test:5173) أو [http://al-noor.my-saas.test:5173](http://al-noor.my-saas.test:5173)
* **سياق التشغيل**: جدول مواعيد مركزي متعدد الفروع، صالة انتظار وطابور حي خاص بكل فرع، ومكتب استقبال وخزينة دفع منفصلين عن الطبيب.

### 🏢 الفروع ومعرفاتها (Branch IDs):
| الفرع | معرف الفرع (UUID) | رابط شاشة صالة الانتظار (Live Queue TV) |
| :--- | :--- | :--- |
| **Branch 1 - فرع المعادي** | `01a0e3a0-e7a1-7062-8096-3bc9b01347d7` | [شاشة انتظار فرع المعادي](http://clinic1.localhost:5173/waiting-room?branch_id=01a0e3a0-e7a1-7062-8096-3bc9b01347d7) |
| **Branch 2 - فرع مدينة نصر** | `01a0e3a0-e7a6-73e4-a2b4-36c444d772c1` | [شاشة انتظار مدينة نصر](http://clinic1.localhost:5173/waiting-room?branch_id=01a0e3a0-e7a6-73e4-a2b4-36c444d772c1) |

### 👥 حسابات الطاقم الطبي والإداري:
| الدور | الاسم | البريد الإلكتروني | كلمة المرور | الفروع المعينة |
| :--- | :--- | :--- | :---: | :--- |
| **طبيب مشترك (Cross-Branch)** | د. أحمد علي (Dr. Ahmed) | `dr.ahmed@clinica.test`<br>*(بديل: `dr.ahmed@alnoor.com`)* | `12345678` | Branch 1 + Branch 2 (`clinic_owner`, `doctor`) |
| **موظف استقبال فرع 1** | سارة - استقبال المعادي | `reception.branch1@clinica.test` | `12345678` | Branch 1 (المعادي فقط) |
| **موظف استقبال فرع 2** | منى - استقبال مدينة نصر | `reception.branch2@clinica.test`<br>*(بديل: `reception.mona@alnoor.com`)* | `12345678` | Branch 2 (مدينة نصر فقط) |

---

## 🏥 2. العيادة (ب): مستشفى الأمل التخصصي (Clinic B — Dual Doctor Shared Reception)

* **معرف المستأجر (Tenant ID)**: `tenant-2`
* **نمط التشغيل (Clinic Mode)**: `polyclinic`
* **قاعدة البيانات المعزولة**: `tenant_tenant-2`
* **روابط الوصول**:
  * رابط المتصفح المحلي: [http://clinic2.localhost:5173](http://clinic2.localhost:5173)
  * روابط النطاق المخصص: [http://clinic2.my-saas.test:5173](http://clinic2.my-saas.test:5173) أو [http://al-amal.my-saas.test:5173](http://al-amal.my-saas.test:5173)
* **سياق التشغيل**: طبيبان يعمل كل منهما حصرياً في جناحه/فرعه (حماية IDOR الصارمة تمنع رؤية أو تعديل كشوفات الجناح الآخر)، وموظفة استقبال مشتركة توزع المرضى وتدير طابور وحسابات الجناحين.

### 🏢 الأجنحة والفروع ومعرفاتها (Branch IDs):
| الفرع / الجناح | معرف الفرع (UUID) | رابط شاشة صالة الانتظار (Live Queue TV) |
| :--- | :--- | :--- |
| **East Wing - الجناح الشرقي** | `01a0e3a0-e9e4-7330-b613-95ebb9cb9d46` | [شاشة انتظار الجناح الشرقي](http://clinic2.localhost:5173/waiting-room?branch_id=01a0e3a0-e9e4-7330-b613-95ebb9cb9d46) |
| **West Wing - الجناح الغربي** | `01a0e3a0-e9e8-73df-ac98-84f2c50af5df` | [شاشة انتظار الجناح الغربي](http://clinic2.localhost:5173/waiting-room?branch_id=01a0e3a0-e9e8-73df-ac98-84f2c50af5df) |

### 👥 حسابات الطاقم الطبي والإداري:
| الدور | الاسم | البريد الإلكتروني | كلمة المرور | الفروع المعينة |
| :--- | :--- | :--- | :---: | :--- |
| **طبيب الجناح الشرقي** | د. طارق خليل (Dr. Tarek) | `dr.tarek@clinicb.test`<br>*(بديل: `dr.tarek@tenant2.com`)* | `12345678` | East Wing فقط (`clinic_owner`, `doctor`) |
| **طبيب الجناح الغربي** | د. خالد عبد الرحمن (Dr. Khaled) | `dr.khaled@clinicb.test`<br>*(بديل: `dr.khaled@tenant2.com`)* | `12345678` | West Wing فقط (`doctor`) |
| **استقبال مشترك (Shared)** | هدى - استقبال مشترك | `reception.shared@clinicb.test`<br>*(بديل: `reception.hoda@alamal.com`)* | `12345678` | East Wing + West Wing (`receptionist`) |

---

## 🏥 3. العيادة (ج): عيادة د. شريف الاستشارية (Clinic C — Solo Doctor Walk-In)

* **معرف المستأجر (Tenant ID)**: `tenant-3`
* **نمط التشغيل (Clinic Mode)**: `solo`
* **قاعدة البيانات المعزولة**: `tenant_tenant-3`
* **روابط الوصول**:
  * رابط المتصفح المحلي: [http://clinic3.localhost:5173](http://clinic3.localhost:5173)
  * روابط النطاق المخصص: [http://clinic3.my-saas.test:5173](http://clinic3.my-saas.test:5173) أو [http://solo.my-saas.test:5173](http://solo.my-saas.test:5173)
* **سياق التشغيل**: طبيب فردي يعمل مستقلاً بالكامل؛ دخول مباشر على مساحة عمل الطبيب (`/doctor/workspace`)، تسجيل فوري للمريض (Inline Registration)، حفظ تلقائي كل 15 ثانية (Auto-save draft)، قياسات حيوية ديناميكية مع مؤشر كتلة الجسم (BMI)، وتحصيل مالي ودفع فوري (Cash, Card, Split) دون الحاجة لموظف استقبال أو كاشير.

### 🏢 الفرع الرئيسي ومعرفه (Branch ID):
| الفرع | معرف الفرع (UUID) | رابط شاشة صالة الانتظار (Live Queue TV) |
| :--- | :--- | :--- |
| **Main Branch - الفرع الرئيسي** | `01a0e3a0-ec21-7237-a50d-93fb4e54dd58` | [شاشة انتظار الفرع الرئيسي](http://clinic3.localhost:5173/waiting-room?branch_id=01a0e3a0-ec21-7237-a50d-93fb4e54dd58) |

### 👥 حساب الطبيب المالك (لا يوجد موظفون إضافيون):
| الدور | الاسم | البريد الإلكتروني | كلمة المرور | الفروع المعينة |
| :--- | :--- | :--- | :---: | :--- |
| **طبيب فردي ومالك العيادة** | د. شريف عبد المنعم (Dr. Sherif) | `dr.sherif@solo.test`<br>*(بديل: `dr.sherif@clinicc.test`)* | `12345678` | Main Branch (`clinic_owner`, `doctor`) |

---

## 🛠️ أوامر إعادة التهيئة وزرع البيانات (Seeding & Reset Commands)

لإعادة ضبط قواعد البيانات وزرع بيئات العمل الثلاث ببياناتها القياسية في أي وقت:

```bash
# داخل مجلد ServerSide:

# 1. زرع الحسابات والفروع المركزية والمستأجرين الثلاثة:
php artisan db:seed --class=UserTenantSeeder

# 2. أو إعادة تهيئة قواعد بيانات المستأجرين وزرعها من الصفر:
php artisan tenants:migrate-fresh
php artisan tenants:seed
```
