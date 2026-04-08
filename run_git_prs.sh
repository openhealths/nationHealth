#!/bin/bash
set -e

# Make sure we're on the right branch and tree is clean except for our new files
git checkout dev

# 1. Migrations
echo "Processing Migrations..."
git checkout -b feature/treatment-plan-migrations dev || git checkout feature/treatment-plan-migrations
git add database/migrations/install/*treatment_plan*.php database/migrations/*treatment_plan*.php
git commit -m "feat: міграції баз даних для планів лікування (Issues #54)" || echo "Nothing to commit for migrations"
git push origin feature/treatment-plan-migrations -f
gh pr create -d -B dev -t "[Draft] БД Міграції для модуля План лікування" -b "Закриває #54. Цей PR додає install та update міграції для таблиць treatment_plans та treatment_plan_activities." || echo "PR already exists"

# 2. Models
echo "Processing Models..."
git checkout -b feature/treatment-plan-models feature/treatment-plan-migrations || git checkout feature/treatment-plan-models
git add app/Models/TreatmentPlan*.php app/Repositories/TreatmentPlan*.php
git commit -m "feat: моделі та репозиторії Планів лікування (Issue #55)" || echo "Nothing to commit for models"
git push origin feature/treatment-plan-models -f
gh pr create -d -B feature/treatment-plan-migrations -t "[Draft] Моделі та репозиторії для модуля План лікування" -b "Закриває #55. Додано Eloquent моделі та класи-репозиторії для взаємодії з таблицями." || echo "PR already exists"

# 3. EHealth API
echo "Processing API..."
git checkout -b feature/treatment-plan-ehealth-api feature/treatment-plan-models || git checkout feature/treatment-plan-ehealth-api
git add app/Classes/eHealth/Api/CarePlan.php app/Classes/eHealth/Api/CarePlanActivity.php app/Classes/eHealth/Api/Approval.php
git commit -m "feat: класи eHealth API для планів лікування (Issue #56)" || echo "Nothing to commit for API"
git push origin feature/treatment-plan-ehealth-api -f
gh pr create -d -B feature/treatment-plan-models -t "[Draft] Інтеграція eHealth API для модуля План лікування" -b "Закриває #56. Створено класи CarePlan, CarePlanActivity та Approval для обробки запитів." || echo "PR already exists"

# 4. Livewire components
echo "Processing Livewire..."
git checkout -b feature/treatment-plan-livewire feature/treatment-plan-ehealth-api || git checkout feature/treatment-plan-livewire
git add app/Livewire/TreatmentPlan/TreatmentPlanCreate.php app/Livewire/TreatmentPlan/TreatmentPlanIndex.php
git commit -m "feat: Livewire компоненти для модуля План лікування (Issue #57)" || echo "Nothing to commit for Livewire"
git push origin feature/treatment-plan-livewire -f
gh pr create -d -B feature/treatment-plan-ehealth-api -t "[Draft] Livewire компоненти для модуля План лікування" -b "Закриває #57. Оновлено існуючі компоненти TreatmentPlanIndex та TreatmentPlanCreate." || echo "PR already exists"

git checkout dev
echo "All done!"
