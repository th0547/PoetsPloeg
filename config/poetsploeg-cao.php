<?php

/*
 * Standaardbeleid volgens de cao Schoonmaak- en Glazenwassersbedrijf 2026–2028, aangevuld met de
 * Arbeidstijdenwet (ATW) en de Wet arbeid en zorg (Wazo). Bronnen: docs/bronnen/.
 * Op de beleidspagina (issue #42) kan elke waarde worden overschreven; een lege database werkt
 * hiermee meteen cao-conform.
 *
 * Per instelling:
 *  - type: int | decimal | bool | select | time | weekday
 *  - favorable: 'higher' of 'lower' = welke kant gunstig is voor de werknemer (voor de waarschuwing
 *    bij afwijken ten nadele); null = geen richting
 *  - employee: tekst voor de leesversie voor medewerkers (:value wordt vervangen); null = intern
 */

return [
    'version' => 'cao Schoonmaak- en Glazenwassersbedrijf 2026–2028 (gecontroleerd 12-11-2026)',

    'groups' => [
        'contract' => [
            'label' => 'Arbeidsduur en contract',
            'items' => [
                'normal_week_hours' => ['label' => 'Normale arbeidsduur gemiddeld', 'type' => 'decimal', 'default' => 38, 'min' => 1, 'max' => 48, 'unit' => 'u/week', 'source' => 'cao art. 13 lid 1', 'favorable' => null, 'employee' => 'Een volledige werkweek is gemiddeld :value uur.'],
                'max_week_hours' => ['label' => 'Maximum gemiddeld per week', 'type' => 'decimal', 'default' => 48, 'min' => 1, 'max' => 60, 'unit' => 'u/week', 'source' => 'cao art. 13 lid 1', 'favorable' => 'lower', 'employee' => 'Je werkt gemiddeld hoogstens :value uur per week.'],
                'max_week_hours_period_weeks' => ['label' => 'Periode voor dat gemiddelde', 'type' => 'int', 'default' => 4, 'min' => 1, 'max' => 52, 'unit' => 'weken', 'source' => 'cao art. 13 lid 1 (12 weken bij wisselende diensten, art. 14 lid 3)', 'favorable' => null, 'employee' => null],
                'extra_hours_threshold' => ['label' => 'Signaal meer-uren vanaf', 'type' => 'int', 'default' => 15, 'min' => 1, 'max' => 100, 'unit' => '% boven contract', 'source' => 'cao art. 13 lid 2–3', 'favorable' => 'lower', 'employee' => 'Werk je in een kwartaal gemiddeld minstens :value% meer dan je contract? Dan biedt je werkgever je een aangepast contract aan.'],
                'extra_hours_period' => ['label' => 'Periode meer-uren', 'type' => 'select', 'default' => 'quarter', 'options' => ['quarter' => 'Kwartaal', 'year' => 'Jaar (bedrijfssluiting of spaaruren)'], 'source' => 'cao art. 13 lid 2–4', 'favorable' => null, 'employee' => null],
                'extra_hours_first_offer' => ['label' => 'Eerste aanbieding aangepast contract', 'type' => 'select', 'default' => '02-01', 'options' => ['01-01' => '1 januari', '02-01' => '1 februari'], 'source' => 'cao art. 13 lid 2', 'favorable' => null, 'employee' => null],
                'extra_hours_small_company' => ['label' => 'Bedrijf heeft minder dan 10 werknemers (meer-uren-regel geldt niet)', 'type' => 'bool', 'default' => false, 'source' => 'cao art. 13 lid 5', 'favorable' => null, 'employee' => null],
                'extra_hours_exclude_replacement' => ['label' => 'Uren voor vervanging (schriftelijk bevestigd) tellen niet mee', 'type' => 'bool', 'default' => true, 'source' => 'cao art. 13 lid 5', 'favorable' => null, 'employee' => null],
                'signal_planned_below_contract' => ['label' => 'Signaal: ingepland onder contracturen', 'type' => 'bool', 'default' => true, 'source' => 'art. 7:628 BW (loon blijft verschuldigd)', 'favorable' => null, 'employee' => null],
                'signal_available_below_contract' => ['label' => 'Signaal: beschikbaarheid onder contracturen (toelichting verplicht)', 'type' => 'bool', 'default' => true, 'source' => 'bedrijfsbeleid', 'favorable' => null, 'employee' => 'Ben je in een week minder beschikbaar dan je contracturen? Dan vragen we om een toelichting. Neem verlof op of overleg met je teamleider.'],
            ],
        ],

        'roster' => [
            'label' => 'Rooster en werktijden',
            'items' => [
                'notice_weeks' => ['label' => 'Rooster bekendmaken vooraf', 'type' => 'int', 'default' => 4, 'min' => 1, 'max' => 12, 'unit' => 'weken', 'source' => 'cao art. 14 lid 3; ATW art. 4:2 (28 dagen)', 'favorable' => 'higher', 'employee' => 'Je rooster is uiterlijk :value weken van tevoren bekend.'],
                'shift_min_hours' => ['label' => 'Dienst minimaal', 'type' => 'decimal', 'default' => 4, 'min' => 0, 'max' => 12, 'unit' => 'uur', 'source' => 'cao art. 14 lid 3', 'favorable' => 'higher', 'employee' => null],
                'shift_max_hours' => ['label' => 'Dienst maximaal', 'type' => 'decimal', 'default' => 9, 'min' => 1, 'max' => 12, 'unit' => 'uur', 'source' => 'cao art. 14 lid 3', 'favorable' => 'lower', 'employee' => null],
                'shift_length_applies' => ['label' => 'Minimale en maximale dienstduur gelden voor', 'type' => 'select', 'default' => 'rotating', 'options' => ['rotating' => 'Alleen wisselende dag-, avond- en nachtdiensten (cao)', 'all' => 'Iedereen', 'none' => 'Niemand (geen waarschuwing)'], 'source' => 'cao art. 14 lid 3', 'favorable' => null, 'employee' => null],
                'max_shifts_per_4_weeks' => ['label' => 'Maximaal aantal diensten per 4 weken', 'type' => 'int', 'default' => 20, 'min' => 1, 'max' => 28, 'unit' => 'diensten', 'source' => 'cao art. 14 lid 3', 'favorable' => 'lower', 'employee' => null],
                'night_shifts_per_52_weeks' => ['label' => 'Nachtdiensten na 02:00 per 52 weken', 'type' => 'int', 'default' => 140, 'min' => 0, 'max' => 260, 'unit' => 'diensten', 'source' => 'cao art. 14 lid 3', 'favorable' => 'lower', 'employee' => null],
                'paid_night_break_minutes' => ['label' => 'Betaalde pauze bij dienst ≥ 4,5 u met ≥ 1 u tussen 00:00–06:00', 'type' => 'int', 'default' => 30, 'min' => 0, 'max' => 120, 'unit' => 'minuten', 'source' => 'cao art. 14 lid 4', 'favorable' => 'higher', 'employee' => 'Werk je minstens 4,5 uur, waarvan minstens een uur tussen 00:00 en 06:00? Dan krijg je :value minuten betaalde pauze.'],
                'min_paid_per_shift_hours' => ['label' => 'Minimale uitbetaling per dienst', 'type' => 'decimal', 'default' => 1, 'min' => 0, 'max' => 8, 'unit' => 'uur', 'source' => 'cao art. 14 lid 5', 'favorable' => 'higher', 'employee' => 'Per dienst krijg je minimaal :value uur betaald.'],
                'rest_daily_hours' => ['label' => 'Dagelijkse rust', 'type' => 'decimal', 'default' => 11, 'min' => 0, 'max' => 24, 'unit' => 'uur', 'source' => 'ATW art. 5:3', 'favorable' => 'higher', 'employee' => 'Tussen twee diensten heb je minstens :value uur rust.'],
                'rest_after_night_hours' => ['label' => 'Rust na nachtdienst die eindigt na 02:00', 'type' => 'decimal', 'default' => 14, 'min' => 0, 'max' => 24, 'unit' => 'uur', 'source' => 'ATW art. 5:8', 'favorable' => 'higher', 'employee' => 'Na een nachtdienst die na 02:00 eindigt, heb je minstens :value uur rust.'],
                'rest_weekly_hours' => ['label' => 'Wekelijkse rust per 7 dagen', 'type' => 'decimal', 'default' => 36, 'min' => 0, 'max' => 72, 'unit' => 'uur', 'source' => 'ATW art. 5:5', 'favorable' => 'higher', 'employee' => 'Per week heb je minstens :value uur aaneengesloten rust.'],
                'max_shift_hours_law' => ['label' => 'Maximaal per dienst (wet)', 'type' => 'decimal', 'default' => 12, 'min' => 1, 'max' => 12, 'unit' => 'uur', 'source' => 'ATW art. 5:7', 'favorable' => 'lower', 'employee' => null],
                'max_week_hours_law' => ['label' => 'Maximaal per week (wet)', 'type' => 'decimal', 'default' => 60, 'min' => 1, 'max' => 60, 'unit' => 'uur', 'source' => 'ATW art. 5:7', 'favorable' => 'lower', 'employee' => null],
                'warn_in_planning' => ['label' => 'Waarschuwingen voor rusttijden en dienstduur in de planning', 'type' => 'bool', 'default' => true, 'source' => 'bedrijfsbeleid', 'favorable' => null, 'employee' => null],
            ],
        ],

        'vacation' => [
            'label' => 'Vakantie-uren',
            'items' => [
                'accrual_statutory_percent' => ['label' => 'Opbouw wettelijk', 'type' => 'decimal', 'default' => 7.69, 'min' => 7.69, 'max' => 20, 'unit' => '% van betaalde uren', 'source' => 'cao art. 28 lid 1', 'favorable' => 'higher', 'employee' => 'Je bouwt :value% wettelijke vakantie-uren op over elk betaald uur.'],
                'accrual_extra_percent' => ['label' => 'Opbouw bovenwettelijk', 'type' => 'decimal', 'default' => 2.31, 'min' => 0, 'max' => 20, 'unit' => '% van betaalde uren', 'source' => 'cao art. 28 lid 1', 'favorable' => 'higher', 'employee' => 'Daarbovenop bouw je :value% bovenwettelijke vakantie-uren op.'],
                'accrual_over_extra_hours' => ['label' => 'Opbouw ook over extra gewerkte uren', 'type' => 'bool', 'default' => true, 'source' => 'cao art. 28 lid 3', 'favorable' => 'higher', 'employee' => null],
                'first_months' => ['label' => 'Beperkte opname in de eerste maanden', 'type' => 'int', 'default' => 3, 'min' => 0, 'max' => 12, 'unit' => 'maanden', 'source' => 'cao art. 28 lid 5', 'favorable' => 'lower', 'employee' => null],
                'first_months_max_hours' => ['label' => 'Maximaal op te nemen in die maanden (fulltime, naar rato)', 'type' => 'decimal', 'default' => 49.4, 'min' => 0, 'max' => 400, 'unit' => 'uur', 'source' => 'cao art. 28 lid 5', 'favorable' => 'higher', 'employee' => 'In je eerste maanden kun je maximaal :value vakantie-uren opnemen (bij fulltime; naar rato bij parttime).'],
                'take_in_advance' => ['label' => 'Daarna mag het jaartegoed vooruit worden opgenomen', 'type' => 'bool', 'default' => true, 'source' => 'cao art. 28 lid 5', 'favorable' => 'higher', 'employee' => null],
                'expire_statutory_years' => ['label' => 'Wettelijke uren vervallen na', 'type' => 'int', 'default' => 1, 'min' => 1, 'max' => 10, 'unit' => 'jaar', 'source' => 'cao art. 28 lid 11', 'favorable' => 'higher', 'employee' => 'Wettelijke vakantie-uren vervallen na :value jaar.'],
                'expire_extra_years' => ['label' => 'Bovenwettelijke uren vervallen na', 'type' => 'int', 'default' => 5, 'min' => 1, 'max' => 10, 'unit' => 'jaar', 'source' => 'cao art. 28 lid 11', 'favorable' => 'higher', 'employee' => 'Bovenwettelijke vakantie-uren vervallen na :value jaar. Je hoort het op tijd voordat uren vervallen.'],
                'expire_notice_weeks' => ['label' => 'Melding vóór het vervallen', 'type' => 'int', 'default' => 8, 'min' => 1, 'max' => 52, 'unit' => 'weken', 'source' => 'cao art. 28 lid 11', 'favorable' => 'higher', 'employee' => null],
                'sickness_not_vacation' => ['label' => 'Ziekte telt niet als vakantie', 'type' => 'bool', 'default' => true, 'source' => 'cao art. 29 lid 2', 'favorable' => 'higher', 'employee' => 'Word je ziek tijdens je vakantie? Dan tellen die uren als ziekte, niet als vakantie.'],
                'no_extra_accrual_after_sick_months' => ['label' => 'Geen bovenwettelijke opbouw na ziekte van', 'type' => 'int', 'default' => 6, 'min' => 1, 'max' => 24, 'unit' => 'maanden', 'source' => 'cao art. 33 lid 3', 'favorable' => 'higher', 'employee' => null],
                'mandatory_weeks' => ['label' => 'Verplicht aantal weken vakantie per jaar (0 = niet verplicht)', 'type' => 'int', 'default' => 0, 'min' => 0, 'max' => 3, 'unit' => 'weken', 'source' => 'cao art. 28 lid 8 (max. 3)', 'favorable' => null, 'employee' => null],
                'collective_vacation' => ['label' => 'Collectieve vakantie (max. 3 weken in juli/augustus, met instemming OR)', 'type' => 'bool', 'default' => false, 'source' => 'cao art. 28 lid 9', 'favorable' => null, 'employee' => null],
                'holiday_allowance_percent' => ['label' => 'Vakantietoeslag', 'type' => 'decimal', 'default' => 8, 'min' => 8, 'max' => 20, 'unit' => '%', 'source' => 'cao art. 26', 'favorable' => 'higher', 'employee' => 'Je krijgt :value% vakantietoeslag, uitbetaald in mei.'],
                'show_balance_employees' => ['label' => 'Indicatief saldo tonen aan medewerkers', 'type' => 'bool', 'default' => true, 'source' => 'bedrijfsbeleid', 'favorable' => null, 'employee' => null],
            ],
        ],

        'sickness' => [
            'label' => 'Ziekte en verzuim',
            'items' => [
                'waiting_days' => ['label' => 'Wachtdagen', 'type' => 'int', 'default' => 0, 'min' => 0, 'max' => 2, 'unit' => 'dagen', 'source' => 'cao art. 33', 'favorable' => 'lower', 'employee' => null],
                'pay_under_2_years' => ['label' => 'Loon bij ziekte, minder dan 2 jaar in dienst', 'type' => 'int', 'default' => 85, 'min' => 70, 'max' => 100, 'unit' => '%', 'source' => 'cao art. 33 lid 1 (ziekte vanaf 1-9-2026)', 'favorable' => 'higher', 'employee' => 'Bij ziekte krijg je :value% van je dagloon (minder dan 2 jaar in dienst), minimaal het minimumloon.'],
                'pay_2_to_6_years' => ['label' => 'Loon bij ziekte, 2 tot 6 jaar in dienst', 'type' => 'int', 'default' => 90, 'min' => 70, 'max' => 100, 'unit' => '%', 'source' => 'cao art. 33 lid 1', 'favorable' => 'higher', 'employee' => 'Bij ziekte krijg je :value% van je dagloon (2 tot 6 jaar in dienst).'],
                'pay_over_6_years' => ['label' => 'Loon bij ziekte, langer dan 6 jaar in dienst', 'type' => 'int', 'default' => 100, 'min' => 70, 'max' => 100, 'unit' => '%', 'source' => 'cao art. 33 lid 1', 'favorable' => 'higher', 'employee' => 'Bij ziekte krijg je :value% van je dagloon (langer dan 6 jaar in dienst).'],
                'report_sick' => ['label' => 'Ziekmelden', 'type' => 'select', 'default' => 'before_shift', 'options' => ['before_shift' => 'Vóór aanvang van de dienst', 'hour_before' => 'Uiterlijk 1 uur vóór aanvang', 'previous_evening' => 'De avond ervoor'], 'source' => 'eigen verzuimprotocol (cao bijlage IXB)', 'favorable' => null, 'employee' => 'Ziek? Meld je bij je planner: :value.'],
                'no_diagnosis' => ['label' => 'Geen reden of diagnose registreren (AVG)', 'type' => 'bool', 'default' => true, 'source' => 'AVG', 'favorable' => null, 'employee' => 'We registreren nooit de reden of diagnose van je ziekte.'],
                'self_report_sick' => ['label' => 'Medewerker mag zich ziek melden via de app', 'type' => 'bool', 'default' => false, 'source' => 'bedrijfsbeleid', 'favorable' => null, 'employee' => null],
            ],
        ],

        'availability' => [
            'label' => 'Beschikbaarheid',
            'items' => [
                'confirm_weekly' => ['label' => 'Beschikbaarheid wekelijks laten bevestigen', 'type' => 'bool', 'default' => true, 'source' => 'bedrijfsbeleid', 'favorable' => null, 'employee' => null],
                'confirm_weeks_ahead' => ['label' => 'Bevestigen voor de week over', 'type' => 'int', 'default' => 5, 'min' => 1, 'max' => 12, 'unit' => 'weken', 'source' => 'roostertermijn + 1', 'favorable' => null, 'employee' => 'Bevestig elke week je beschikbaarheid voor de week over :value weken, zodat het rooster op tijd klaar is.'],
                'confirm_mail_weekday' => ['label' => 'Herinneringsmail op', 'type' => 'weekday', 'default' => 1, 'source' => 'bedrijfsbeleid', 'favorable' => null, 'employee' => null],
                'confirm_mail_time' => ['label' => 'Om', 'type' => 'time', 'default' => '09:00', 'source' => 'bedrijfsbeleid', 'favorable' => null, 'employee' => null],
                'confirm_reminder_days' => ['label' => 'Herinnering na', 'type' => 'int', 'default' => 3, 'min' => 1, 'max' => 6, 'unit' => 'dagen', 'source' => 'bedrijfsbeleid', 'favorable' => null, 'employee' => null],
                'after_confirm' => ['label' => 'Na bevestigen', 'type' => 'select', 'default' => 'request', 'options' => ['locked' => 'Vergrendeld', 'request' => 'Alleen wijzigen via verzoek', 'free' => 'Vrij aanpasbaar'], 'source' => 'bedrijfsbeleid', 'favorable' => null, 'employee' => 'Na bevestigen: :value.'],
                'other_employer_field' => ['label' => 'Veld "werk bij andere werkgever"', 'type' => 'bool', 'default' => true, 'source' => 'cao art. 14 lid 2', 'favorable' => null, 'employee' => null],
            ],
        ],

        'requests' => [
            'label' => 'Verlofaanvragen en goedkeuring',
            'items' => [
                'replacement_required' => ['label' => 'Vervanger nodig vóór goedkeuring', 'type' => 'bool', 'default' => true, 'source' => 'bedrijfsbeleid', 'favorable' => null, 'employee' => null],
                'auto_approve_without_shifts' => ['label' => 'Automatisch goedkeuren als er geen diensten zijn', 'type' => 'bool', 'default' => true, 'source' => 'bedrijfsbeleid', 'favorable' => null, 'employee' => null],
                'estimate_hours' => ['label' => 'Uren schatten bij aanvragen buiten de planning (diensten → vaste bezetting → contracturen)', 'type' => 'bool', 'default' => true, 'source' => 'cao art. 30/31 (uren die je normaal zou werken)', 'favorable' => null, 'employee' => null],
                'employee_withdraw_pending' => ['label' => 'Medewerker mag een openstaande aanvraag intrekken', 'type' => 'bool', 'default' => true, 'source' => 'bedrijfsbeleid', 'favorable' => null, 'employee' => null],
                'employee_withdraw_approved' => ['label' => 'Medewerker mag een goedgekeurde aanvraag intrekken', 'type' => 'bool', 'default' => false, 'source' => 'bedrijfsbeleid', 'favorable' => null, 'employee' => null],
            ],
        ],

        'privacy' => [
            'label' => 'Privacy en rechten',
            'items' => [
                'group_care_types' => ['label' => 'Zorgsoorten in tellers groeperen als "Zorg/kort verzuim"', 'type' => 'bool', 'default' => true, 'source' => 'AVG', 'favorable' => null, 'employee' => null],
                'edit_roles' => ['label' => 'Wie mag het beleid wijzigen', 'type' => 'select', 'default' => 'planners', 'options' => ['planners' => 'Planners en teamleiders', 'teamleaders' => 'Alleen teamleiders'], 'source' => 'bedrijfsbeleid', 'favorable' => null, 'employee' => null],
                'visible_to_employees' => ['label' => 'Beleid zichtbaar voor medewerkers (alleen lezen)', 'type' => 'bool', 'default' => true, 'source' => 'bedrijfsbeleid', 'favorable' => null, 'employee' => null],
            ],
        ],
    ],

    /*
     * Verlofsoorten. counts_as: vacation | short_leave | care | sickness | unpaid | unavailable | other.
     * unit: hours | days | workdays | weeks_x_weekhours | x_weekhours. max: aantal in die eenheid (null = geen).
     * period: event | year | 12_months. refusal: yes | serious | no.
     */
    'leave_types' => [
        'verlof' => ['name' => 'Vakantie (snipperuren)', 'group' => 'Vakantie', 'counts_as' => 'vacation', 'paid' => 100, 'paid_by' => 'werkgever', 'unit' => 'hours', 'max' => null, 'period' => 'year', 'self_request' => true, 'direct' => false, 'min_days_ahead' => 0, 'refusal' => 'serious', 'proof' => false, 'note_required' => false, 'without_shift' => false, 'source' => 'cao art. 28', 'explanation' => 'Vakantie-uren vraag je zelf aan; je spreekt met je werkgever af wanneer.', 'active' => true],
        'huwelijk' => ['name' => 'Huwelijk / partnerschap', 'group' => 'Bijzonder verlof', 'counts_as' => 'short_leave', 'paid' => 100, 'paid_by' => 'werkgever', 'unit' => 'days', 'max' => 1, 'period' => 'event', 'self_request' => true, 'direct' => false, 'min_days_ahead' => 0, 'refusal' => 'no', 'proof' => false, 'note_required' => false, 'without_shift' => false, 'source' => 'cao art. 30 lid 1', 'explanation' => '1 dag betaald verlof als je trouwt, geregistreerd partner wordt of een samenlevingscontract tekent bij de notaris.', 'active' => true],
        'overlijden_1' => ['name' => 'Overlijden partner, ouder of kind', 'group' => 'Bijzonder verlof', 'counts_as' => 'short_leave', 'paid' => 100, 'paid_by' => 'werkgever', 'unit' => 'workdays', 'max' => 5, 'period' => 'event', 'self_request' => true, 'direct' => true, 'min_days_ahead' => 0, 'refusal' => 'no', 'proof' => false, 'note_required' => false, 'without_shift' => false, 'source' => 'cao art. 30 lid 2', 'explanation' => '5 werkdagen achter elkaar vanaf de dag van overlijden (1e graad, ook schoonouders en stiefkinderen), betaald over de uren die je zou werken.', 'active' => true],
        'overlijden_2' => ['name' => 'Overlijden overige familie', 'group' => 'Bijzonder verlof', 'counts_as' => 'short_leave', 'paid' => 100, 'paid_by' => 'werkgever', 'unit' => 'days', 'max' => 2, 'period' => 'event', 'self_request' => true, 'direct' => true, 'min_days_ahead' => 0, 'refusal' => 'no', 'proof' => false, 'note_required' => false, 'without_shift' => false, 'source' => 'cao art. 30 lid 3', 'explanation' => '2 dagen voor de crematie of begrafenis van (schoon)grootouder, (schoon)broer of -zus of kleinkind.', 'active' => true],
        'overlijden_buitenland' => ['name' => 'Overlijden familie in het buitenland', 'group' => 'Bijzonder verlof', 'counts_as' => 'short_leave', 'paid' => 0, 'paid_by' => 'werkgever', 'unit' => 'days', 'max' => null, 'period' => 'event', 'self_request' => true, 'direct' => false, 'min_days_ahead' => 0, 'refusal' => 'serious', 'proof' => false, 'note_required' => true, 'without_shift' => false, 'source' => 'cao art. 30 lid 4', 'explanation' => 'Moet je naar het buitenland voor een overlijden in de familie? Dan kun je betaald of onbetaald verlof opnemen.', 'active' => true],
        'verjaardag' => ['name' => 'Verjaardagsverlof', 'group' => 'Bijzonder verlof', 'counts_as' => 'short_leave', 'paid' => 100, 'paid_by' => 'werkgever', 'unit' => 'days', 'max' => 1, 'period' => 'year', 'self_request' => true, 'direct' => false, 'min_days_ahead' => 0, 'refusal' => 'serious', 'proof' => false, 'note_required' => false, 'without_shift' => false, 'source' => 'cao art. 31 (ruimer: op elke dag)', 'explanation' => '1 dag per jaar vrij voor je verjaardag; je kiest zelf de dag. Je krijgt de uren betaald die je die dag zou werken.', 'active' => true],
        'mantelzorg' => ['name' => 'Mantelzorg', 'group' => 'Zorg', 'counts_as' => 'care', 'paid' => 0, 'paid_by' => 'werkgever', 'unit' => 'hours', 'max' => null, 'period' => 'year', 'self_request' => true, 'direct' => false, 'min_days_ahead' => 0, 'refusal' => 'serious', 'proof' => false, 'note_required' => false, 'without_shift' => false, 'source' => 'cao art. 32 lid 1', 'explanation' => 'Vrij vragen voor mantelzorg mag je werkgever in principe niet weigeren.', 'active' => true],
        'onbetaald' => ['name' => 'Onbetaald verlof', 'group' => 'Overig', 'counts_as' => 'unpaid', 'paid' => 0, 'paid_by' => 'werkgever', 'unit' => 'hours', 'max' => null, 'period' => 'year', 'self_request' => true, 'direct' => false, 'min_days_ahead' => 0, 'refusal' => 'serious', 'proof' => false, 'note_required' => false, 'without_shift' => false, 'source' => 'cao art. 32 lid 2', 'explanation' => 'Je werkgever stemt hier zoveel mogelijk mee in en vertelt wat het betekent voor je sociale zekerheid en pensioen.', 'active' => true],
        'calamiteit' => ['name' => 'Calamiteiten- en kort verzuimverlof', 'group' => 'Bijzonder verlof', 'counts_as' => 'short_leave', 'paid' => 100, 'paid_by' => 'werkgever', 'unit' => 'hours', 'max' => null, 'period' => 'event', 'self_request' => false, 'direct' => true, 'min_days_ahead' => 0, 'refusal' => 'no', 'proof' => false, 'note_required' => false, 'without_shift' => false, 'source' => 'Wazo art. 4:1', 'explanation' => 'Korte tijd vrij met behoud van loon bij onvoorziene omstandigheden, bijv. de bevalling van je partner of een noodsituatie thuis. Meld het direct bij je planner.', 'active' => true],
        'huisarts' => ['name' => 'Huisarts', 'group' => 'Zorg', 'counts_as' => 'care', 'paid' => 100, 'paid_by' => 'werkgever', 'unit' => 'hours', 'max' => null, 'period' => 'event', 'self_request' => true, 'direct' => false, 'min_days_ahead' => 0, 'refusal' => 'no', 'proof' => false, 'note_required' => false, 'without_shift' => false, 'source' => 'Wazo art. 4:1 lid 2c', 'explanation' => 'Betaald als het spoedeisend of onvoorzien is, of niet buiten je werktijd kan.', 'active' => true],
        'tandarts' => ['name' => 'Tandarts', 'group' => 'Zorg', 'counts_as' => 'care', 'paid' => 100, 'paid_by' => 'werkgever', 'unit' => 'hours', 'max' => null, 'period' => 'event', 'self_request' => true, 'direct' => false, 'min_days_ahead' => 0, 'refusal' => 'no', 'proof' => false, 'note_required' => false, 'without_shift' => false, 'source' => 'Wazo art. 4:1 lid 2c', 'explanation' => 'Betaald als het spoedeisend of onvoorzien is, of niet buiten je werktijd kan.', 'active' => true],
        'specialist' => ['name' => 'Ziekenhuis / specialist', 'group' => 'Zorg', 'counts_as' => 'care', 'paid' => 100, 'paid_by' => 'werkgever', 'unit' => 'hours', 'max' => null, 'period' => 'event', 'self_request' => true, 'direct' => false, 'min_days_ahead' => 0, 'refusal' => 'no', 'proof' => false, 'note_required' => false, 'without_shift' => false, 'source' => 'Wazo art. 4:1 lid 2c', 'explanation' => 'Betaald als het spoedeisend of onvoorzien is, of niet buiten je werktijd kan. Ook voor noodzakelijke begeleiding van een naaste.', 'active' => true],
        'fysiotherapie' => ['name' => 'Fysiotherapie', 'group' => 'Zorg', 'counts_as' => 'care', 'paid' => 0, 'paid_by' => 'werkgever', 'unit' => 'hours', 'max' => null, 'period' => 'event', 'self_request' => true, 'direct' => false, 'min_days_ahead' => 0, 'refusal' => 'serious', 'proof' => false, 'note_required' => false, 'without_shift' => false, 'source' => 'bedrijfsbeleid', 'explanation' => 'Onbetaald; plan het zo mogelijk buiten je werktijd.', 'active' => true],
        'zorgverlof_kort' => ['name' => 'Kortdurend zorgverlof', 'group' => 'Zorg', 'counts_as' => 'care', 'paid' => 70, 'paid_by' => 'werkgever', 'unit' => 'x_weekhours', 'max' => 2, 'period' => '12_months', 'self_request' => true, 'direct' => false, 'min_days_ahead' => 0, 'refusal' => 'serious', 'proof' => false, 'note_required' => false, 'without_shift' => false, 'source' => 'Wazo art. 5:1, 5:2, 5:6', 'explanation' => 'Voor de noodzakelijke zorg voor een zieke naaste: maximaal 2× je weekuren per 12 maanden, 70% loon (minimaal het minimumloon).', 'active' => true],
        'zorgverlof_lang' => ['name' => 'Langdurend zorgverlof', 'group' => 'Zorg', 'counts_as' => 'care', 'paid' => 0, 'paid_by' => 'werkgever', 'unit' => 'x_weekhours', 'max' => 6, 'period' => '12_months', 'self_request' => true, 'direct' => false, 'min_days_ahead' => 14, 'refusal' => 'serious', 'proof' => false, 'note_required' => false, 'without_shift' => false, 'source' => 'Wazo art. 5:9, 5:10', 'explanation' => 'Voor langere zorg voor een naaste: maximaal 6× je weekuren per 12 maanden, onbetaald.', 'active' => true],
        'geboorteverlof' => ['name' => 'Geboorteverlof partner', 'group' => 'Ouderschap', 'counts_as' => 'short_leave', 'paid' => 100, 'paid_by' => 'werkgever', 'unit' => 'x_weekhours', 'max' => 1, 'period' => 'event', 'self_request' => true, 'direct' => false, 'min_days_ahead' => 0, 'refusal' => 'no', 'proof' => false, 'note_required' => false, 'without_shift' => false, 'source' => 'Wazo art. 4:2', 'explanation' => '1× je weekuren betaald verlof, op te nemen binnen 4 weken na de bevalling.', 'active' => true],
        'geboorteverlof_aanvullend' => ['name' => 'Aanvullend geboorteverlof', 'group' => 'Ouderschap', 'counts_as' => 'unpaid', 'paid' => 70, 'paid_by' => 'UWV', 'unit' => 'x_weekhours', 'max' => 5, 'period' => 'event', 'self_request' => true, 'direct' => false, 'min_days_ahead' => 28, 'refusal' => 'no', 'proof' => false, 'note_required' => false, 'without_shift' => false, 'source' => 'Wazo art. 4:2a, 4:2b', 'explanation' => 'Maximaal 5 weken binnen 6 maanden na de bevalling; je krijgt een uitkering van 70% van UWV.', 'active' => true],
        'ouderschapsverlof' => ['name' => 'Ouderschapsverlof', 'group' => 'Ouderschap', 'counts_as' => 'unpaid', 'paid' => 70, 'paid_by' => 'UWV', 'unit' => 'x_weekhours', 'max' => 26, 'period' => 'event', 'self_request' => true, 'direct' => false, 'min_days_ahead' => 60, 'refusal' => 'serious', 'proof' => false, 'note_required' => false, 'without_shift' => false, 'source' => 'Wazo art. 6:1–6:3', 'explanation' => '26× je weekuren per kind. Over 9× je weekuren in het eerste levensjaar krijg je 70% van UWV; de rest is onbetaald.', 'active' => true],
        'zwangerschapsverlof' => ['name' => 'Zwangerschaps- en bevallingsverlof', 'group' => 'Ouderschap', 'counts_as' => 'unpaid', 'paid' => 100, 'paid_by' => 'UWV', 'unit' => 'weeks', 'max' => 16, 'period' => 'event', 'self_request' => true, 'direct' => false, 'min_days_ahead' => 21, 'refusal' => 'no', 'proof' => true, 'note_required' => false, 'without_shift' => false, 'source' => 'Wazo art. 3:1', 'explanation' => 'Minimaal 16 weken: 6 weken vóór (10 bij een meerling) en 10 weken na de bevalling. De uitkering komt van UWV.', 'active' => true],
        'adoptieverlof' => ['name' => 'Adoptie- en pleegzorgverlof', 'group' => 'Ouderschap', 'counts_as' => 'unpaid', 'paid' => 100, 'paid_by' => 'UWV', 'unit' => 'weeks', 'max' => 6, 'period' => 'event', 'self_request' => true, 'direct' => false, 'min_days_ahead' => 21, 'refusal' => 'no', 'proof' => true, 'note_required' => false, 'without_shift' => false, 'source' => 'Wazo art. 3:2', 'explanation' => 'Maximaal 6 aaneengesloten weken binnen 26 weken; de uitkering komt van UWV.', 'active' => true],
        'ziek' => ['name' => 'Ziek', 'group' => 'Ziekte', 'counts_as' => 'sickness', 'paid' => 85, 'paid_by' => 'werkgever', 'unit' => 'hours', 'max' => null, 'period' => 'event', 'self_request' => false, 'direct' => true, 'min_days_ahead' => 0, 'refusal' => 'no', 'proof' => false, 'note_required' => false, 'without_shift' => false, 'source' => 'cao art. 29, 33', 'explanation' => 'Ziekte meld je direct bij je planner. Ziekte telt nooit als vakantie.', 'active' => true],
        'niet_beschikbaar' => ['name' => 'Niet beschikbaar', 'group' => 'Overig', 'counts_as' => 'unavailable', 'paid' => 0, 'paid_by' => 'werkgever', 'unit' => 'hours', 'max' => null, 'period' => 'event', 'self_request' => true, 'direct' => true, 'min_days_ahead' => 0, 'refusal' => 'yes', 'proof' => false, 'note_required' => false, 'without_shift' => true, 'source' => 'bedrijfsbeleid', 'explanation' => 'Een dag waarop je niet ingepland bent en ook niet ingepland wilt worden. Telt niet als verlof.', 'active' => true],
        'bijzonder' => ['name' => 'Bijzonder verlof (overig)', 'group' => 'Bijzonder verlof', 'counts_as' => 'short_leave', 'paid' => 100, 'paid_by' => 'werkgever', 'unit' => 'hours', 'max' => null, 'period' => 'event', 'self_request' => true, 'direct' => false, 'min_days_ahead' => 0, 'refusal' => 'serious', 'proof' => false, 'note_required' => true, 'without_shift' => false, 'source' => 'bedrijfsbeleid', 'explanation' => 'Voor andere bijzondere omstandigheden, in overleg met je teamleider.', 'active' => true],
        'verhuisdag' => ['name' => 'Verhuisdag', 'group' => 'Bijzonder verlof', 'counts_as' => 'short_leave', 'paid' => 100, 'paid_by' => 'werkgever', 'unit' => 'days', 'max' => 1, 'period' => 'year', 'self_request' => true, 'direct' => false, 'min_days_ahead' => 14, 'refusal' => 'serious', 'proof' => false, 'note_required' => false, 'without_shift' => false, 'source' => 'bedrijfsbeleid (niet in de cao)', 'explanation' => '1 dag per jaar als je verhuist.', 'active' => false],
        'overig' => ['name' => 'Overig', 'group' => 'Overig', 'counts_as' => 'other', 'paid' => 0, 'paid_by' => 'werkgever', 'unit' => 'hours', 'max' => null, 'period' => 'event', 'self_request' => false, 'direct' => false, 'min_days_ahead' => 0, 'refusal' => 'yes', 'proof' => false, 'note_required' => true, 'without_shift' => false, 'source' => 'bedrijfsbeleid', 'explanation' => 'Voor de planner: afwezigheid die nergens anders onder valt.', 'active' => true],
    ],

    'counts_as' => [
        'vacation' => 'Vakantie',
        'short_leave' => 'Kort verzuim / bijzonder verlof',
        'care' => 'Zorg',
        'sickness' => 'Ziekte',
        'unpaid' => 'Onbetaald / UWV',
        'unavailable' => 'Niet beschikbaar (geen verlof)',
        'other' => 'Overig',
    ],

    'units' => [
        'hours' => 'uur',
        'days' => 'dagen',
        'workdays' => 'werkdagen',
        'weeks' => 'weken',
        'x_weekhours' => '× weekuren',
    ],

    'periods' => [
        'event' => 'per keer',
        'year' => 'per jaar',
        '12_months' => 'per 12 maanden',
    ],

    'refusal' => [
        'yes' => 'Ja',
        'serious' => 'Alleen bij zwaarwegend belang',
        'no' => 'Nee',
    ],
];
