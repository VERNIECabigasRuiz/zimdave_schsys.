<?php

namespace App\Data;

/**
 * Regions of the Philippines and their Schools Division Offices, from the DepEd
 * Regional & Division Offices Directory (BARMM divisions are under MBHTE).
 *
 * Some division names exist in two regions (e.g. San Fernando City in Region I
 * and Region III), so always check a division together with its region.
 */
final class PhilippineDivisions
{
    private const REGIONS = [
        'NCR' => ['National Capital Region', [
            'Caloocan City', 'Las Piñas City', 'Makati City', 'Malabon City', 'Mandaluyong City', 'Manila',
            'Marikina City', 'Muntinlupa City', 'Navotas City', 'Parañaque City', 'Pasay City', 'Pasig City',
            'Quezon City', 'San Juan City', 'Taguig City - Pateros', 'Valenzuela City',
        ]],
        'CAR' => ['Cordillera Administrative Region', [
            'Abra', 'Apayao', 'Baguio City', 'Benguet', 'Ifugao', 'Kalinga', 'Mountain Province', 'Tabuk City',
        ]],
        'Region I' => ['Ilocos Region', [
            'Alaminos City', 'Batac City', 'Candon City', 'Dagupan City', 'Ilocos Norte', 'Ilocos Sur', 'La Union',
            'Laoag City', 'Pangasinan I', 'Pangasinan II', 'San Carlos City', 'San Fernando City', 'Urdaneta City',
            'Vigan City',
        ]],
        'Region II' => ['Cagayan Valley', [
            'Batanes', 'Cagayan', 'Cauayan City', 'Ilagan City', 'Isabela', 'Nueva Vizcaya', 'Quirino',
            'Santiago City', 'Tuguegarao City',
        ]],
        'Region III' => ['Central Luzon', [
            'Angeles City', 'Aurora', 'Balanga City', 'Baliwag City', 'Bataan', 'Bulacan', 'Cabanatuan City',
            'Gapan City', 'Mabalacat City', 'Malolos City', 'Meycauayan City', 'Nueva Ecija', 'Olongapo City',
            'Pampanga', 'San Fernando City', 'San Jose City', 'San Jose del Monte City', 'Science City of Muñoz',
            'Tarlac', 'Tarlac City', 'Zambales',
        ]],
        'Region IV-A' => ['CALABARZON', [
            'Antipolo City', 'Bacoor City', 'Batangas', 'Batangas City', 'Biñan City', 'Cabuyao City', 'Calaca City',
            'Calamba City', 'Cavite', 'Cavite City', 'Dasmariñas City', 'General Trias City', 'Imus City', 'Laguna',
            'Lipa City', 'Lucena City', 'Quezon', 'Rizal', 'San Pablo City', 'San Pedro City', 'Santa Rosa City',
            'Santo Tomas City', 'Tanauan City', 'Tayabas City',
        ]],
        'Region IV-B' => ['MIMAROPA', [
            'Calapan City', 'Marinduque', 'Occidental Mindoro', 'Oriental Mindoro', 'Palawan', 'Puerto Princesa City',
            'Romblon',
        ]],
        'Region V' => ['Bicol Region', [
            'Albay', 'Camarines Norte', 'Camarines Sur', 'Catanduanes', 'Iriga City', 'Legazpi City', 'Ligao City',
            'Masbate', 'Masbate City', 'Naga City', 'Sorsogon', 'Sorsogon City', 'Tabaco City',
        ]],
        'Region VI' => ['Western Visayas', [
            'Aklan', 'Antique', 'Capiz', 'Guimaras', 'Iloilo', 'Iloilo City', 'Passi City', 'Roxas City',
        ]],
        'NIR' => ['Negros Island Region', [
            'Bacolod City', 'Bago City', 'Bais City', 'Bayawan City', 'Cadiz City', 'Canlaon City', 'Dumaguete City',
            'Escalante City', 'Guihulngan City', 'Himamaylan City', 'Kabankalan City', 'La Carlota City',
            'Negros Occidental', 'Negros Oriental', 'Sagay City', 'San Carlos City', 'Silay City', 'Sipalay City',
            'Siquijor', 'Talisay City', 'Tanjay City', 'Victorias City',
        ]],
        'Region VII' => ['Central Visayas', [
            'Bogo City', 'Bohol', 'Carcar City', 'Cebu', 'Cebu City', 'Danao City', 'Lapu-Lapu City', 'Mandaue City',
            'Naga City', 'Tagbilaran City', 'Talisay City', 'Toledo City',
        ]],
        'Region VIII' => ['Eastern Visayas', [
            'Baybay City', 'Biliran', 'Borongan City', 'Calbayog City', 'Catbalogan City', 'Eastern Samar', 'Leyte',
            'Maasin City', 'Northern Samar', 'Ormoc City', 'Samar', 'Southern Leyte', 'Tacloban City',
        ]],
        'Region IX' => ['Zamboanga Peninsula', [
            'Dapitan City', 'Dipolog City', 'Isabela City', 'Pagadian City', 'Zamboanga City', 'Zamboanga del Norte',
            'Zamboanga del Sur', 'Zamboanga Sibugay',
        ]],
        'Region X' => ['Northern Mindanao', [
            'Bukidnon', 'Cagayan de Oro City', 'Camiguin', 'El Salvador City', 'Gingoog City', 'Iligan City',
            'Lanao del Norte', 'Malaybalay City', 'Misamis Occidental', 'Misamis Oriental', 'Oroquieta City',
            'Ozamiz City', 'Tangub City', 'Valencia City',
        ]],
        'Region XI' => ['Davao Region', [
            'Davao City', 'Davao de Oro', 'Davao del Norte', 'Davao del Sur', 'Davao Occidental', 'Davao Oriental',
            'Digos City', 'Mati City', 'Panabo City', 'Samal City', 'Tagum City',
        ]],
        'Region XII' => ['SOCCSKSARGEN', [
            'Cotabato', 'General Santos City', 'Kidapawan City', 'Koronadal City', 'Sarangani', 'South Cotabato',
            'Sultan Kudarat', 'Tacurong City',
        ]],
        'Region XIII' => ['Caraga', [
            'Agusan del Norte', 'Agusan del Sur', 'Bayugan City', 'Bislig City', 'Butuan City', 'Cabadbaran City',
            'Dinagat Islands', 'Siargao', 'Surigao City', 'Surigao del Norte', 'Surigao del Sur', 'Tandag City',
        ]],
        'BARMM' => ['Bangsamoro Autonomous Region in Muslim Mindanao', [
            'Basilan', 'Cotabato City', 'Lamitan City', 'Lanao del Sur I', 'Lanao del Sur II', 'Maguindanao I',
            'Maguindanao II', 'Marawi City', 'Sulu', 'Tawi-Tawi',
        ]],
    ];

    /** @return list<string> region codes as stored on a school, e.g. "Region VII" */
    public static function regions(): array
    {
        return array_keys(self::REGIONS);
    }

    /** "Region VII – Central Visayas"; a region not on the list is returned as it is. */
    public static function label(string $region): string
    {
        return isset(self::REGIONS[$region]) ? $region.' – '.self::REGIONS[$region][0] : $region;
    }

    /** @return list<string> */
    public static function divisionsOf(string $region): array
    {
        return self::REGIONS[$region][1] ?? [];
    }

    public static function belongsTo(string $division, string $region): bool
    {
        return \in_array($division, self::divisionsOf($region), true);
    }

    /** @return list<string> every division name once, alphabetical */
    public static function allDivisions(): array
    {
        $all = array_values(array_unique(array_merge(...array_column(self::REGIONS, 1))));
        sort($all, \SORT_STRING | \SORT_FLAG_CASE);

        return $all;
    }
}
