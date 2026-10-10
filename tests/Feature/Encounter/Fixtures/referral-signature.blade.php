<div>
    <x-signature-modal method="sign" :except-actions="['cancel_encounter']" />
    <x-signature-modal method="cancelSelectedEncounter" :only-actions="['cancel_encounter']" />
</div>
