<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Models\User;
use App\Models\LegalEntity;
use App\Classes\eHealth\Request;
use App\Classes\eHealth\Exceptions\ApiException;
use Illuminate\Support\Facades\Session;
use Spatie\Permission\Models\Role;

class EmployeeApi
{
    public const URL = '/api/employees';

    /**
     * Authenticate user with eHealth
     *
     * @param  string  $code
     * @param  string  $legalEntityUUID
     * @return mixed
     * @throws ApiException
     */
    public static function authenticate(string $code, string $legalEntityUUID): mixed
    {
        $user = User::find(Session::get(config('ehealth.api.auth_ehealth')));
        $legalEntity = LegalEntity::whereUuid($legalEntityUUID)->first();

        setPermissionsTeamId($legalEntity->id);

        $role = Session::get('first_login_role');

        if (!$user || $role) {
            $permissions = Role::where('name', $role)
                ->whereGuardName('ehealth')
                ->firstOrFail()
                ->permissions()
                ->pluck('name')
                ->toArray();

            $scope = implode(' ', $permissions);
        } else {
            $scope = $user->getScopes();
        }

        $data = [
            'token' => [
                'client_id' => $legalEntity->client_id ?? '',
                'client_secret' => $legalEntity->client_secret ?? '',
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => config('ehealth.api.redirect_uri'),
                'scope' => $scope
            ]
        ];

        return new Request('POST', config('ehealth.api.oauth.tokens'), $data, false)->sendRequest();
    }

    /**
     * @throws ApiException
     */
    public static function getUserDetails(): array
    {
        return new Request('GET', config('ehealth.api.oauth.user'), [])->sendRequest();
    }

    public static function schemaRequest(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-04/schema#',
            'definitions' => [
                'phone' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => [
                            'type' => 'string',
                            'enum' => [
                                'MOBILE',
                                'LANDLINE'
                            ]
                        ],
                        'number' => [
                            'type' => 'string',
                            'pattern' => '^\+38[0-9]{10}$'
                        ]
                    ],
                    'required' => [
                        'type',
                        'number'
                    ],
                    'additionalProperties' => false
                ],
                'document' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => [
                            'type' => 'string',
                            'enum' => [
                                'PASSPORT',
                                'NATIONAL_ID',
                                'BIRTH_CERTIFICATE',
                                'TEMPORARY_CERTIFICATE'
                            ]
                        ],
                        'number' => [
                            'type' => 'string'
                        ]
                    ],
                    'required' => [
                        'type',
                        'number'
                    ],
                    'additionalProperties' => false
                ],
                'education' => [
                    'type' => 'object',
                    'properties' => [
                        'country' => [
                            'type' => 'string',
                            'enum' => [
                                'UA'
                            ]
                        ],
                        'city' => [
                            'type' => 'string'
                        ],
                        'institution_name' => [
                            'type' => 'string'
                        ],
                        'issued_date' => [
                            'type' => 'string'
                        ],
                        'diploma_number' => [
                            'type' => 'string'
                        ],
                        'degree' => [
                            'type' => 'string',
                            'enum' => [
                                'Молодший спеціаліст',
                                'Бакалавр',
                                'Спеціаліст',
                                'Магістр'
                            ]
                        ],
                        'speciality' => [
                            'type' => 'string'
                        ]
                    ],
                    'required' => [
                        'country',
                        'city',
                        'institution_name',
                        'diploma_number',
                        'degree',
                        'speciality'
                    ],
                    'additionalProperties' => false
                ],
                'qualification' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => [
                            'type' => 'string',
                            'enum' => [
                                'Інтернатура',
                                'Спеціалізація',
                                'Передатестаційний цикл',
                                'Тематичне вдосконалення',
                                'Курси інформації',
                                'Стажування'
                            ]
                        ],
                        'institution_name' => [
                            'type' => 'string'
                        ],
                        'speciality' => [
                            'type' => 'string'
                        ],
                        'issued_date' => [
                            'type' => 'string',
                            'format' => 'date'
                        ],
                        'certificate_number' => [
                            'type' => 'string',
                            'format' => 'date'
                        ]
                    ],
                    'required' => [
                        'type',
                        'institution_name',
                        'speciality'
                    ],
                    'additionalProperties' => false
                ],
                'speciality' => [
                    'type' => 'object',
                    'properties' => [
                        'speciality' => [
                            'type' => 'string',
                            'enum' => [
                                'Терапевт',
                                'Педіатр',
                                'Сімейний лікар'
                            ]
                        ],
                        'speciality_officio' => [
                            'type' => 'boolean'
                        ],
                        'level' => [
                            'type' => 'string',
                            'enum' => [
                                'Друга категорія',
                                'Перша категорія',
                                'Вища категорія'
                            ]
                        ],
                        'qualification_type' => [
                            'type' => 'string',
                            'enum' => [
                                'Присвоєння',
                                'Підтвердження'
                            ]
                        ],
                        'attestation_name' => [
                            'type' => 'string'
                        ],
                        'attestation_date' => [
                            'type' => 'string',
                            'format' => 'date'
                        ],
                        'valid_to_date' => [
                            'type' => 'string',
                            'format' => 'date'
                        ],
                        'certificate_number' => [
                            'type' => 'string'
                        ]
                    ],
                    'required' => [
                        'speciality',
                        'speciality_officio',
                        'level',
                        'qualification_type',
                        'attestation_name',
                        'certificate_number'
                    ],
                    'additionalProperties' => false
                ],
                'science_degree' => [
                    'type' => 'object',
                    'properties' => [
                        'country' => [
                            'type' => 'string',
                            'enum' => [
                                'UA'
                            ]
                        ],
                        'city' => [
                            'type' => 'string'
                        ],
                        'degree' => [
                            'type' => 'string',
                            'enum' => [
                                'Доктор філософії',
                                'Кандидат наук',
                                'Доктор наук'
                            ]
                        ],
                        'institution_name' => [
                            'type' => 'string'
                        ],
                        'diploma_number' => [
                            'type' => 'string'
                        ],
                        'speciality' => [
                            'type' => 'string',
                            'enum' => [
                                'Терапевт',
                                'Педіатр',
                                'Сімейний лікар'
                            ]
                        ],
                        'issued_date' => [
                            'type' => 'string',
                            'format' => 'date'
                        ]
                    ],
                    'required' => [
                        'country',
                        'city',
                        'degree',
                        'institution_name',
                        'diploma_number',
                        'speciality'
                    ],
                    'additionalProperties' => false
                ],
                'party' => [
                    'type' => 'object',
                    'properties' => [
                        'first_name' => [
                            'type' => 'string'
                        ],
                        'last_name' => [
                            'type' => 'string'
                        ],
                        'second_name' => [
                            'type' => 'string'
                        ],
                        'birth_date' => [
                            'type' => 'string',
                            'format' => 'date'
                        ],
                        'gender' => [
                            'type' => 'string',
                            'enum' => [
                                'MALE',
                                'FEMALE'
                            ]
                        ],
                        'tax_id' => [
                            'type' => 'string',
                            'pattern' => '^[1-9]([0-9]{7}|[0-9]{9})$'
                        ],
                        'email' => [
                            'type' => 'string',
                            'format' => 'email'
                        ],
                        'documents' => [
                            'type' => 'array',
                            'items' => [
                                '$ref' => '#/definitions/document'
                            ]
                        ],
                        'phones' => [
                            'type' => 'array',
                            'items' => [
                                '$ref' => '#/definitions/phone'
                            ]
                        ]
                    ],
                    'required' => [
                        'first_name',
                        'last_name',
                        'birth_date',
                        'gender',
                        'tax_id',
                        'email',
                        'documents',
                        'phones'
                    ],
                    'additionalProperties' => false
                ]
            ],
            'type' => 'object',
            'properties' => [
                'employee_request' => [
                    'type' => 'object',
                    'properties' => [
                        'legal_entity_uuid' => [
                            'type' => 'string',
                            'pattern' => '^[0-9a-f]{8}(-?)[0-9a-f]{4}(-?)[0-9a-f]{4}(-?)[0-9a-f]{4}(-?)[0-9a-f]{12}$'
                        ],
                        'division_uuid' => [
                            'type' => 'string',
                            'pattern' => '^[0-9a-f]{8}(-?)[0-9a-f]{4}(-?)[0-9a-f]{4}(-?)[0-9a-f]{4}(-?)[0-9a-f]{12}$'
                        ],
                        'employee_id' => [
                            'type' => 'string',
                            'pattern' => '^[0-9a-f]{8}(-?)[0-9a-f]{4}(-?)[0-9a-f]{4}(-?)[0-9a-f]{4}(-?)[0-9a-f]{12}$'
                        ],
                        'position' => [
                            'type' => 'string'
                        ],
                        'start_date' => [
                            'type' => 'string',
                            'format' => 'date'
                        ],
                        'end_date' => [
                            'type' => 'string',
                            'format' => 'date'
                        ],
                        'status' => [
                            'type' => 'string',
                            'enum' => [
                                'NEW'
                            ]
                        ],
                        'employee_type' => [
                            'type' => 'string',
                            'enum' => [
                                'DOCTOR',
                                'HR',
                                'ADMIN',
                                'OWNER'
                            ]
                        ],
                        'party' => [
                            'type' => 'object',
                            'properties' => [
                                'items' => [
                                    '$ref' => '#/definitions/party'
                                ]
                            ]
                        ],
                        'doctor' => [
                            'type' => 'object',
                            'properties' => [
                                'educations' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/definitions/education'
                                    ]
                                ],
                                'qualifications' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/definitions/qualification'
                                    ]
                                ],
                                'specialities' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/definitions/speciality'
                                    ]
                                ],
                                'science_degree' => [
                                    'type' => 'object',
                                    'items' => [
                                        '$ref' => '#/definitions/science_degree'
                                    ]
                                ]
                            ],
                            'required' => [
                                'educations',
                                'specialities'
                            ]
                        ]
                    ],
                    'required' => [
                        'legal_entity_id',
                        'position',
                        'start_date',
                        'status',
                        'employee_type',
                        'party'
                    ]
                ]
            ],
            'required' => [
                'employee_request'
            ]
        ];
    }

    public static function schemaResponse(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'string'
                ],
                'division_id' => [
                    'type' => 'string'
                ],
                'legal_entity_id' => [
                    'type' => 'string'
                ],
                'position' => [
                    'type' => 'string'
                ],
                'start_date' => [
                    'type' => 'string'
                ],
                'end_date' => [
                    'type' => 'string'
                ],
                'status' => [
                    'enum' => [
                        'NEW',
                        'REJECTED',
                        'EXPIRED',
                        'APPROVED'
                    ]
                ],
                'employee_type' => [
                    'type' => 'string'
                ],
                'party' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => [
                            'type' => 'string'
                        ],
                        'first_name' => [
                            'type' => 'string'
                        ],
                        'last_name' => [
                            'type' => 'string'
                        ],
                        'second_name' => [
                            'type' => 'string'
                        ],
                        'birth_date' => [
                            'type' => 'string'
                        ],
                        'gender' => [
                            'type' => 'string'
                        ],
                        'no_tax_id' => [
                            'type' => 'boolean'
                        ],
                        'tax_id' => [
                            'type' => 'string'
                        ],
                        'email' => [
                            'type' => 'string'
                        ],
                        'documents' => [
                            'type' => 'array'
                        ],
                        'phones' => [
                            'type' => 'array'
                        ],
                        'working_experience' => [
                            'type' => 'number'
                        ],
                        'about_myself' => [
                            'type' => 'string'
                        ]
                    ],
                    'required' => [
                        'first_name',
                        'last_name',
                        'birth_date',
                        'gender'
                    ]
                ],
                'doctor' => [
                    'type' => 'object',
                    'properties' => [
                        'educations' => [
                            'type' => 'array'
                        ],
                        'qualifications' => [
                            'type' => 'array'
                        ],
                        'specialities' => [
                            'type' => 'array'
                        ],
                        'science_degree' => [
                            'type' => 'object',
                            'properties' => [
                                'country' => [
                                    'type' => 'string'
                                ],
                                'city' => [
                                    'type' => 'string'
                                ],
                                'degree' => [
                                    'type' => 'string'
                                ],
                                'institution_name' => [
                                    'type' => 'string'
                                ],
                                'diploma_number' => [
                                    'type' => 'string'
                                ],
                                'speciality' => [
                                    'type' => 'string'
                                ],
                                'issued_date' => [
                                    'type' => 'string'
                                ]
                            ],
                            'required' => [
                                'country',
                                'city',
                                'degree',
                                'institution_name',
                                'diploma_number',
                                'speciality'
                            ]
                        ]
                    ],
                    'required' => [
                        'educations',
                        'specialities'
                    ]
                ],
                'inserted_at' => [
                    'type' => 'string'
                ],
                'updated_at' => [
                    'type' => 'string'
                ]
            ],
            'required' => [
                'position',
                'status',
                'employee_type',
                'id',
                'inserted_at',
                'updated_at'
            ]
        ];
    }
}
