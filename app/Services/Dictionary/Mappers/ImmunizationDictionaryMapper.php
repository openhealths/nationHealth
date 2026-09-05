<?php

declare(strict_types=1);

namespace App\Services\Dictionary\Mappers;

final class ImmunizationDictionaryMapper
{
    /**
     * Official correspondence between target disease codes and vaccine codes.
     *
     * @see https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/1637253162/Immunization+dictionaries
     *
     * @var array<string, array<int, string>>
     */
    private const TARGET_DISEASE_VACCINES = [
        'Anthrax' => [
            'Anthrax'
        ],

        'Cholera' => [
            'CHOLERA'
        ],

        'COVID_19' => [
            'SarsCov2_mRNA',
            'SarsCov2_nRVv',
            'SarsCov2_RVv',
            'SarsCov2_Pr',
            'SarsCov2_Rc_lp',
            'SarsCov2_Inact',
            'SarsCov2_DNA',
            'SarsCov2_RNA'
        ],

        'Diphtheria' => [
            'Diphtheria',
            'DT',
            'DTaP',
            'DTaPHepB',
            'DTaPHepBIPV',
            'DTaPHib',
            'DTaPHibHepB',
            'DTaPHibHepBIPV',
            'DTaPHibIPV',
            'DTaPIPV',
            'DTIPV',
            'DTPIPV',
            'DTwP',
            'DTwPHepB',
            'DTwPHib',
            'DTwPHibHepB',
            'DTwPHibHepBIPV',
            'Td',
            'TdaP',
            'TdaPIPV',
            'TdIPV'
        ],

        'Hepatitis_A' => [
            'HepA',
            'HepAHepB',
            'TyphoidHepA'
        ],

        'Hepatitis_B' => [
            'DTaPHepB',
            'DTaPHepBIPV',
            'DTaPHibHepB',
            'DTaPHibHepBIPV',
            'DTwPHepB',
            'DTwPHibHepB',
            'DTwPHibHepBIPV',
            'HepAHepB',
            'HepB'
        ],

        'HIB' => [
            'DTaPHib',
            'DTaPHibHepB',
            'DTaPHibHepBIPV',
            'DTaPHibIPV',
            'DTwPHib',
            'DTwPHibHepB',
            'DTwPHibHepBIPV',
            'HIB',
            'HibMenC',
            'PCV10'
        ],

        'HPV' => [
            'HPV'
        ],

        'Japanese_encephalitis' => [
            'JE_Inactd',
            'JE_LiveAtd',
            'JE_Rec'
        ],

        'Leptospirosis' => [
            'Leptospirosis'
        ],

        'Measles' => [
            'Measles',
            'MM',
            'MMR',
            'MMRV',
            'MR'
        ],

        'Meningococcal' => [
            'HibMenC',
            'Men_ACWY_135',
            'MenA_conj',
            'MenAC',
            'MenACW',
            'MenB',
            'MenBC',
            'MenC_conj'
        ],

        'Mumps' => [
            'MM',
            'MMR',
            'MMRV',
            'Mumps'
        ],

        'Pertussis' => [
            'aP',
            'DTaP',
            'DTaPHepB',
            'DTaPHepBIPV',
            'DTaPHib',
            'DTaPHibHepB',
            'DTaPHibHepBIPV',
            'DTaPHibIPV',
            'DTaPIPV',
            'DTPIPV',
            'DTwP',
            'DTwPHepB',
            'DTwPHib',
            'DTwPHibHepB',
            'DTwPHibHepBIPV',
            'TdaP',
            'TdaPIPV'
        ],

        'Plague' => [
            'Plague'
        ],

        'Pneumococcal' => [
            'PCV10',
            'Pneumo_conj',
            'Pneumo_ps'
        ],

        'Polio' => [
            'bOPV',
            'tOPV',
            'DTaPHepBIPV',
            'DTaPHibHepBIPV',
            'DTaPHibIPV',
            'DTaPIPV',
            'DTIPV',
            'DTPIPV',
            'DTwPHibHepBIPV',
            'IPV',
            'TdaPIPV',
            'TdIPV'
        ],

        'Q_fever' => [
            'Q_Vax'
        ],

        'Rabies' => [
            'Rabies'
        ],

        'Rotavirus' => [
            'Rotavirus'
        ],

        'Rubella' => [
            'MMR',
            'MMRV',
            'MR',
            'Rubella'
        ],

        'Seasonal_influenza' => [
            'Influenza'
        ],

        'Tetanus' => [
            'DT',
            'DTaP',
            'DTaPHepB',
            'DTaPHepBIPV',
            'DTaPHib',
            'DTaPHibHepB',
            'DTaPHibHepBIPV',
            'DTaPHibIPV',
            'DTaPIPV',
            'DTIPV',
            'DTPIPV',
            'DTwP',
            'DTwPHepB',
            'DTwPHib',
            'DTwPHibHepB',
            'DTwPHibHepBIPV',
            'Td',
            'TdaP',
            'TdaPIPV',
            'TdIPV',
            'TT'
        ],

        'Tick-borne_encephalitis' => [
            'TBE'
        ],

        'Tuberculosis' => [
            'BCG'
        ],

        'Tularemia' => [
            'Tularemia'
        ],

        'Typhoid fever' => [
            'Typhoid_conj',
            'TyphoidHepA',
            'ViPS'
        ],

        'Varicella' => [
            'MMRV',
            'Varicella'
        ],

        'Yellow_fever' => [
            'YF'
        ],

        'Monkey_Pox' => [
            'mpox'
        ]
    ];

    /**
     * Get target disease codes allowed for the specified vaccine.
     *
     * @return array<int, string>
     */
    public function targetDiseaseCodesForVaccine(string $vaccineCode): array
    {
        return collect(self::TARGET_DISEASE_VACCINES)
            ->filter(static fn (array $relatedVaccineCodes) => in_array($vaccineCode, $relatedVaccineCodes, true))
            ->keys()
            ->values()
            ->toArray();
    }

    /**
     * Determine whether the target disease corresponds to the vaccine code.
     */
    public function isTargetDiseaseAllowed(string $vaccineCode, string $targetDiseaseCode): bool {
        return in_array($targetDiseaseCode, $this->targetDiseaseCodesForVaccine($vaccineCode), true);
    }

    /**
     * Map vaccine codes and target diseases into vaccine search options.
     *
     * @param  array<string, string>  $vaccineCodes
     * @param  array<string, string>  $targetDiseases
     * @return array<int, array{
     *     code: string,
     *     name: string,
     *     targetDiseases: array<int, array{code: string, name: string}>
     * }>
     */
    public function map(array $vaccineCodes, array $targetDiseases): array
    {
        return collect($vaccineCodes)
            ->map(function (string $vaccineName, string $vaccineCode) use ($targetDiseases) {
                $diseases = collect($this->targetDiseaseCodesForVaccine($vaccineCode))
                    ->map(
                        static fn (string $diseaseCode) => [
                            'code' => $diseaseCode,
                            'name' => $targetDiseases[$diseaseCode] ?? $diseaseCode
                        ]
                    )
                    ->values()
                    ->toArray();

                return [
                    'code' => $vaccineCode,
                    'name' => $vaccineName,
                    'targetDiseases' => $diseases
                ];
            })
            ->values()
            ->toArray();
    }
}