<?php

return [
    // Hoe ver vooruit diensten automatisch uit taaksjablonen worden aangemaakt.
    'generate_weeks_ahead' => (int) env('POETSPLOEG_GENERATE_WEEKS_AHEAD', 12),

    // Na zoveel gedraaide diensten op een pand geldt een medewerker als ingewerkt op dat pand.
    'learn_building_after_shifts' => (int) env('POETSPLOEG_LEARN_BUILDING_AFTER', 2),

    // Commit die in de image is gebakken (docker/productie/Dockerfile); getoond op /gezond.
    'commit' => env('POETSPLOEG_COMMIT', 'onbekend'),

    'weekdays' => [
        1 => 'maandag', 2 => 'dinsdag', 3 => 'woensdag', 4 => 'donderdag',
        5 => 'vrijdag', 6 => 'zaterdag', 7 => 'zondag',
    ],

    'weekdays_short' => [
        1 => 'ma', 2 => 'di', 3 => 'wo', 4 => 'do', 5 => 'vr', 6 => 'za', 7 => 'zo',
    ],
];
