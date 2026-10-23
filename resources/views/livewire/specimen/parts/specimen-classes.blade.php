<script>
    class SpecimenContainer {
        constructor(obj = null) {
            this.identifier = '';
            this.description = '';
            this.typeCode = '';
            this.typeText = '';
            this.capacityValue = '';
            this.capacityCode = '';
            this.specimenQuantityValue = '';
            this.specimenQuantityCode = '';
            this.additiveCode = '';
            this.additiveText = '';

            if (obj) {
                Object.assign(this, JSON.parse(JSON.stringify(obj)));
            }
        }
    }

    class Specimen {
        constructor(obj = null) {
            this.uuid = crypto.randomUUID();
            this.typeCode = '';
            this.typeText = '';
            this.conditionCode = '';
            this.conditionText = '';
            this.receivedDate = '';
            this.receivedTime = '';
            this.note = '';
            this.parentIds = [];
            this.collectorType = 'current';
            this.collectorId = '';
            this.collectedType = 'date_time';
            this.collectedDate = '';
            this.collectedTime = '';
            this.collectedPeriodRange = '';
            this.collectedPeriodStartTime = '';
            this.collectedPeriodEndTime = '';
            this.durationValue = '';
            this.durationCode = '';
            this.quantityValue = '';
            this.quantityCode = '';
            this.methodCode = '';
            this.bodySiteCode = '';
            this.fastingStatusCode = '';
            this.procedureId = '';
            this.containers = [new SpecimenContainer()];

            if (obj) {
                Object.assign(this, JSON.parse(JSON.stringify(obj)));
            }
        }
    }
</script>
