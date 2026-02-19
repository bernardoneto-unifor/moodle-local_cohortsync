<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_cohortsync';
$plugin->version = 2026011401; // 14/01/2026
$plugin->requires = 2024100800; // Moodle 5 (LTS)
$plugin->supported = [405, 501]; // Moodle 4.5.x → 5.1.x
$plugin->maturity = MATURITY_STABLE;
$plugin->release = 'v5.1 (Tema via sessão no login)';

$plugin->author = 'Bernardo Neto';
$plugin->authoremail = 'bernardoneto@unifor.br';
$plugin->description = 'Plugin para sincronização automática de coortes e aplicação de temas por categoria de curso';
