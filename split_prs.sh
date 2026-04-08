#!/bin/bash
set -e

# Create Issues
echo "Creating issues..."
ISSUE1=$(gh issue create -t "БД Міграції для модуля План лікування" -b "Реалізувати install та update міграції бази даних для сутностей treatment_plans та treatment_plan_activities відповідно до вимог ТЗ 3.10.")
ISSUE2=$(gh issue create -t "Моделі та репозиторії для модуля План лікування" -b "Реалізувати Eloquent моделі та репозиторні класи для treatment_plans та treatment_plan_activities.")
ISSUE3=$(gh issue create -t "Інтеграція eHealth API для модуля План лікування" -b "Створити eHealth API класи (CarePlan, CarePlanActivity, Approval) для синхронізації планів лікування з ЦБД.")
ISSUE4=$(gh issue create -t "Livewire компоненти для модуля План лікування" -b "Оновити компоненти Livewire для роботи з планом лікування, додавши логіку збереження чернеток і валідації полів згідно ТЗ 3.10.")

echo "Issues created: $ISSUE1, $ISSUE2, $ISSUE3, $ISSUE4"

# 1. Migrations
echo "Processing Migrations..."
git checkout -b feature/treatment-plan-migrations dev
git add database/migrations/install/*treatment_plan*.php database/migrations/*treatment_plan*.php
git commit -m "feat: міграції баз даних для планів лікування"
git push -u origin feature/treatment-plan-migrations
gh pr create -d -B dev -t "[Draft] БД Міграції для модуля План лікування" -b "Закриває $ISSUE1. Цей PR додає install та update міграції для таблиць treatment_plans та treatment_plan_activities."

# 2. Models
echo "Processing Models..."
git checkout -b feature/treatment-plan-models feature/treatment-plan-migrations
git add app/Models/TreatmentPlan*.php app/Repositories/TreatmentPlan*.php
git commit -m "feat: моделі та репозиторії Планів лікування"
git push -u origin feature/treatment-plan-models
gh pr create -d -B feature/treatment-plan-migrations -t "[Draft] Моделі та репозиторії для модуля План лікування" -b "Закриває $ISSUE2. Додано Eloquent моделі та класи-репозиторії для взаємодії з таблицями."

# 3. EHealth API
echo "Processing API..."
git checkout -b feature/treatment-plan-ehealth-api feature/treatment-plan-models
git add app/Classes/eHealth/Api/CarePlan.php app/Classes/eHealth/Api/CarePlanActivity.php app/Classes/eHealth/Api/Approval.php
git commit -m "feat: класи eHealth API для планів лікування"
git push -u origin feature/treatment-plan-ehealth-api
gh pr create -d -B feature/treatment-plan-models -t "[Draft] Інтеграція eHealth API для модуля План лікування" -b "Закриває $ISSUE3. Створено класи CarePlan, CarePlanActivity та Approval для обробки запитів."

# 4. Livewire components
echo "Processing Livewire..."
git checkout -b feature/treatment-plan-livewire feature/treatment-plan-ehealth-api
git add app/Livewire/TreatmentPlan/TreatmentPlanCreate.php app/Livewire/TreatmentPlan/TreatmentPlanIndex.php
git commit -m "feat: Livewire компоненти для модуля План лікування"
git push -u origin feature/treatment-plan-livewire
gh pr create -d -B feature/treatment-plan-ehealth-api -t "[Draft] Livewire компоненти для модуля План лікування" -b "Закриває $ISSUE4. Оновлено існуючі компоненти TreatmentPlanIndex та TreatmentPlanCreate."

# Go back to dev
git checkout dev
echo "All done!"

