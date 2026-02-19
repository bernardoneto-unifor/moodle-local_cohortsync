<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    // Matrícula em curso.
    [
        'eventname' => '\core\event\user_enrolment_created',
        'callback' => '\local_cohortsync\observer::user_enrolled',
        'internal' => false,
    ],

    // Desmatrícula de curso.
    [
        'eventname' => '\core\event\user_enrolment_deleted',
        'callback' => '\local_cohortsync\observer::user_unenrolled',
        'internal' => false,
    ],

    // Login: aplica o tema do plugin como tema de sessão (sem alterar o tema do coorte).
    [
        'eventname' => '\core\event\user_loggedin',
        'callback' => '\local_cohortsync\observer::user_loggedin',
        'internal' => false,
    ],
];
