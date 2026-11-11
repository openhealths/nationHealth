# Nursing workplace and patient summary: TV39 U07 / G01

Every patient-summary read and synchronization action checks the existing person/preperson policy before patient lookup, API calls or queue effects. Summary operations use the approved `patient_summary:read` scope, including for ASSISTANT workplaces at PMD and SMD. Individual entity read scopes are not required for the summary endpoints.

Procedure-summary synchronization reads and validates all pages before storing any records or showing success. A malformed later page leaves local procedure storage unchanged. The summary uses the shared session-message consumer and disables duplicate legacy feedback.

The actual forms enforce primary-source nursing records for conditions, observations, procedures, diagnostic reports, devices, device associations and detected issues. The approved immunization exception remains available. These checks complement the module-specific PMD, SMD and ASSISTANT authorization tests.

Verification: 35 tests / 94 assertions covering all 21 summary actions, denial before side effects, PMD/SMD ASSISTANT summary access, ten actual form validations, complete pagination and malformed later-page atomicity. Tests run only against the isolated `tv39_1201` database. Live ЕСОЗ signing and user-assisted workplace UAT are tracked separately in the umbrella report.
