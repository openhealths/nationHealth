<?php

declare(strict_types=1);

namespace App\Classes\eHealth;

use App\Classes\eHealth\Api\Address;
use App\Classes\eHealth\Api\Approval;
use App\Classes\eHealth\Api\Auth;
use App\Classes\eHealth\Api\Contract;
use App\Classes\eHealth\Api\CarePlan;
use App\Classes\eHealth\Api\CarePlanActivity;
use App\Classes\eHealth\Api\ContractRequest;
use App\Classes\eHealth\Api\Declaration;
use App\Classes\eHealth\Api\DeclarationRequest;
use App\Classes\eHealth\Api\DeviceDefinition;
use App\Classes\eHealth\Api\DiagnoseGroup;
use App\Classes\eHealth\Api\Dictionary;
use App\Classes\eHealth\Api\Division;
use App\Classes\eHealth\Api\Drug;
use App\Classes\eHealth\Api\Employee;
use App\Classes\eHealth\Api\EmployeeRequest;
use App\Classes\eHealth\Api\EmployeeRole;
use App\Classes\eHealth\Api\Equipment;
use App\Classes\eHealth\Api\ForbiddenGroup;
use App\Classes\eHealth\Api\HealthcareService;
use App\Classes\eHealth\Api\Job;
use App\Classes\eHealth\Api\LegalEntity;
use App\Classes\eHealth\Api\License;
use App\Classes\eHealth\Api\MedicalProgram;
use App\Classes\eHealth\Api\Party;
use App\Classes\eHealth\Api\Patient;
use App\Classes\eHealth\Api\Patient\ClinicalImpression;
use App\Classes\eHealth\Api\Patient\Condition;
use App\Classes\eHealth\Api\Patient\DiagnosticReport;
use App\Classes\eHealth\Api\Patient\Encounter;
use App\Classes\eHealth\Api\Patient\Episode;
use App\Classes\eHealth\Api\Patient\Immunization;
use App\Classes\eHealth\Api\Patient\Observation;
use App\Classes\eHealth\Api\Patient\Procedure;
use App\Classes\eHealth\Api\Person;
use App\Classes\eHealth\Api\PersonRequest;
use App\Classes\eHealth\Api\RuleEngineRules;
use App\Classes\eHealth\Api\Service;
use App\Classes\eHealth\Api\Verification;

final class EHealth
{
    public static function approval(): Approval
    {
        return app(Approval::class);
    }

    public static function license(): License
    {
        return app(License::class);
    }

    public static function job(): Job
    {
        return app(Job::class);
    }

    public static function deviceDefinition(): DeviceDefinition
    {
        return app(DeviceDefinition::class);
    }

    public static function personRequest(): PersonRequest
    {
        return app(PersonRequest::class);
    }

    public static function person(): Person
    {
        return app(Person::class);
    }

    public static function patient(): Patient
    {
        return app(Patient::class);
    }

    public static function declarationRequest(): DeclarationRequest
    {
        return app(DeclarationRequest::class);
    }

    public static function declaration(): Declaration
    {
        return app(Declaration::class);
    }

    public static function ruleEngineRules(): RuleEngineRules
    {
        return app(RuleEngineRules::class);
    }

    public static function division(): Division
    {
        return app(Division::class);
    }

    public static function healthcareService(): HealthcareService
    {
        return app(HealthcareService::class);
    }

    public static function employee(): Employee
    {
        return app(Employee::class);
    }

    public static function employeeRequest(): EmployeeRequest
    {
        return app(EmployeeRequest::class);
    }

    public static function employeeRole(): EmployeeRole
    {
        return app(EmployeeRole::class);
    }

    public static function equipment(): Equipment
    {
        return app(Equipment::class);
    }

    public static function party(): Party
    {
        return app(Party::class);
    }

    public static function legalEntity(): LegalEntity
    {
        return app(LegalEntity::class);
    }

    public static function contractRequest(): ContractRequest
    {
        return app(ContractRequest::class);
    }

    public static function contract(): Contract
    {
        return app(Contract::class);
    }

    public static function auth(): Auth
    {
        return app(Auth::class);
    }

    public static function medicalProgram(): MedicalProgram
    {
        return app(MedicalProgram::class);
    }

    public static function address(): Address
    {
        return app(Address::class);
    }

    public static function dictionary(): Dictionary
    {
        return app(Dictionary::class);
    }

    public static function service(): Service
    {
        return app(Service::class);
    }

    public static function carePlan(): CarePlan
    {
        return app(CarePlan::class);
    }

    public static function carePlanActivity(): CarePlanActivity
    {
        return app(CarePlanActivity::class);
    }

    public static function verification(): Verification
    {
        return app(Verification::class);
    }

    public static function drug(): Drug
    {
        return app(Drug::class);
    }

    public static function diagnoseGroup(): DiagnoseGroup
    {
        return app(DiagnoseGroup::class);
    }

    public static function forbiddenGroup(): ForbiddenGroup
    {
        return app(ForbiddenGroup::class);
    }

    // Medical Events
    public static function episode(): Episode
    {
        return app(Episode::class);
    }

    public static function encounter(): Encounter
    {
        return app(Encounter::class);
    }

    public static function clinicalImpression(): ClinicalImpression
    {
        return app(ClinicalImpression::class);
    }

    public static function immunization(): Immunization
    {
        return app(Immunization::class);
    }

    public static function procedure(): Procedure
    {
        return app(Procedure::class);
    }

    public static function condition(): Condition
    {
        return app(Condition::class);
    }

    public static function observation(): Observation
    {
        return app(Observation::class);
    }

    public static function diagnosticReport(): DiagnosticReport
    {
        return app(DiagnosticReport::class);
    }
}
