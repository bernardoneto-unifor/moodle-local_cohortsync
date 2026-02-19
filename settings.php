<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // Categoria principal do plugin
    $ADMIN->add('localplugins', new admin_category('local_cohortsync_category', 
        get_string('pluginname', 'local_cohortsync')));
    
    // Páginas de configuração
    $ADMIN->add('local_cohortsync_category', new admin_externalpage(
        'local_cohortsync_themeconfig',
        '🎨 Configurar Temas por Coorte',
        new moodle_url('/local/cohortsync/admin_themeconfig.php')
    ));
    
    $ADMIN->add('local_cohortsync_category', new admin_externalpage(
        'local_cohortsync_sync',
        '🔄 Sincronização Manual',
        new moodle_url('/local/cohortsync/admin_sync.php')
    ));
    
    $ADMIN->add('local_cohortsync_category', new admin_externalpage(
        'local_cohortsync_cleanup',
        '🧹 Limpeza de Coortes',
        new moodle_url('/local/cohortsync/admin_cleanup.php')
    ));
}