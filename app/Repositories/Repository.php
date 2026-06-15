<?php

declare(strict_types=1);

namespace App\Repositories;

final class Repository
{
    public static function address(): AddressRepository
    {
        return app(AddressRepository::class);
    }

    public static function phone(): PhoneRepository
    {
        return app(PhoneRepository::class);
    }

    public static function document(): DocumentRepository
    {
        return app(DocumentRepository::class);
    }

    public static function employee(): EmployeeRepository
    {
        return app(EmployeeRepository::class);
    }

    public static function employeeRole(): EmployeeRoleRepository
    {
        return app(EmployeeRoleRepository::class);
    }

    public static function division(): DivisionRepository
    {
        return app(DivisionRepository::class);
    }

    public static function healthcareService(): HealthcareServiceRepository
    {
        return app(HealthcareServiceRepository::class);
    }

    public static function declarationRequest(): DeclarationRequestRepository
    {
        return app(DeclarationRequestRepository::class);
    }

    public static function declaration(): DeclarationRepository
    {
        return app(DeclarationRepository::class);
    }

    public static function user(): UserRepository
    {
        return app(UserRepository::class);
    }

    public static function personRequest(): PersonRequestRepository
    {
        return app(PersonRequestRepository::class);
    }

    public static function person(): PersonRepository
    {
        return app(PersonRepository::class);
    }

    public static function equipment(): EquipmentRepository
    {
        return app(EquipmentRepository::class);
    }

    public static function legalEntity(): LegalEntityRepository
    {
        return app(LegalEntityRepository::class);
    }

    public static function contract(): ContractRepository
    {
        return app(ContractRepository::class);
    }

    public static function contractRequest(): ContractRequestRepository
    {
        return app(ContractRequestRepository::class);
    }

    public static function authenticationMethod(): AuthenticationMethodRepository
    {
        return app(AuthenticationMethodRepository::class);
    }

    public static function confidantPerson(): ConfidantPersonRepository
    {
        return app(ConfidantPersonRepository::class);
    }

    public static function confidantPersonRelationshipRequestRepository(): ConfidantPersonRelationshipRequestRepository
    {
        return app(ConfidantPersonRelationshipRequestRepository::class);
    }

    public static function revision(): RevisionRepository
    {
        return app(RevisionRepository::class);
    }

    public static function party(): PartyRepository
    {
        return app(PartyRepository::class);
    }

    public static function approval(): ApprovalRepository
    {
        return app(ApprovalRepository::class);
    }

    public static function observationConfig(): ObservationConfigRepository
    {
        return app(ObservationConfigRepository::class);
    }
}
