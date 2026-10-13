<?php

return [
    // Hoe ver vooruit diensten automatisch uit taaksjablonen worden aangemaakt.
    'generate_weeks_ahead' => (int) env('NOVON_GENERATE_WEEKS_AHEAD', 12),

    // Na zoveel gedraaide diensten op een pand geldt een medewerker als ingewerkt op dat pand.
    'learn_building_after_shifts' => (int) env('NOVON_LEARN_BUILDING_AFTER', 2),

    // GitHub-webhook voor automatisch uitrollen (leeg = uitgeschakeld). Zie DEPLOY.md.
    'deploy_webhook_secret' => env('DEPLOY_WEBHOOK_SECRET'),
    'deploy_branch' => env('DEPLOY_BRANCH', 'main'),

    'weekdays' => [
        1 => 'maandag', 2 => 'dinsdag', 3 => 'woensdag', 4 => 'donderdag',
        5 => 'vrijdag', 6 => 'zaterdag', 7 => 'zondag',
    ],

    'weekdays_short' => [
        1 => 'ma', 2 => 'di', 3 => 'wo', 4 => 'do', 5 => 'vr', 6 => 'za', 7 => 'zo',
    ],
];
