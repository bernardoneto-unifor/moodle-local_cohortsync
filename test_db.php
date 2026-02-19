<?php
require_once('../../config.php');
require_admin();

echo "Verificando tabelas...<br>";

$tables = [
    'local_cohortsync_config' => 'Tabela de configuração',
    'cohort' => 'Tabela de coortes',
    'cohort_members' => 'Tabela de membros'
];

foreach ($tables as $table => $desc) {
    if ($DB->get_manager()->table_exists($table)) {
        echo "✅ $desc: EXISTE<br>";
    } else {
        echo "❌ $desc: FALTANDO<br>";
    }
}