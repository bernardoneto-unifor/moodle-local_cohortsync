<?php
require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/cohort/lib.php');

admin_externalpage_setup('local_cohortsync_sync');
$PAGE->set_title('Sincronizar Coortes');
$PAGE->set_heading('Sincronizar Coortes');

echo $OUTPUT->header();
echo $OUTPUT->heading('Sincronização de Coortes');

if (isset($_POST['sync']) && confirm_sesskey()) {
    echo '<div class="alert alert-info">Iniciando sincronização...</div>';
    
    $total_added = 0;
    
    // Buscar categorias com coortes
    $categories = $DB->get_records_sql("
        SELECT DISTINCT cat.id, cat.name
        FROM {course_categories} cat
        JOIN {context} ctx ON ctx.instanceid = cat.id AND ctx.contextlevel = 40
        JOIN {cohort} co ON co.contextid = ctx.id
        WHERE cat.id > 0
    ");
    
    foreach ($categories as $category) {
        echo "<h4>Categoria: {$category->name}</h4>";
        
        $cohorts = $DB->get_records_sql("
            SELECT co.id, co.name 
            FROM {cohort} co
            JOIN {context} ctx ON ctx.id = co.contextid
            WHERE ctx.instanceid = ? AND ctx.contextlevel = 40
        ", [$category->id]);
        
        foreach ($cohorts as $cohort) {
            echo "Coorte: <strong>{$cohort->name}</strong><br>";
            
            $users = $DB->get_records_sql("
                SELECT DISTINCT u.id, u.username
                FROM {user} u
                JOIN {user_enrolments} ue ON ue.userid = u.id
                JOIN {enrol} e ON e.id = ue.enrolid
                JOIN {course} c ON c.id = e.courseid
                WHERE c.category = ? AND u.deleted = 0
            ", [$category->id]);
            
            $added = 0;
            foreach ($users as $user) {
                if (!$DB->record_exists('cohort_members', ['cohortid' => $cohort->id, 'userid' => $user->id])) {
                    cohort_add_member($cohort->id, $user->id);
                    $added++;
                }
            }
            echo "→ {$added} usuários adicionados<br>";
            $total_added += $added;
        }
    }
    
    echo '<div class="alert alert-success mt-3">';
    echo "<strong>Concluído! {$total_added} usuários sincronizados.</strong>";
    echo '</div>';
} else {
    echo '<div class="card">';
    echo '<div class="card-body">';
    echo '<p>Sincronize usuários com coortes baseado na categoria dos cursos.</p>';
    echo '<form method="post">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '" />';
    echo '<button type="submit" name="sync" value="1" class="btn btn-primary">Executar Sincronização</button>';
    echo '</form>';
    echo '</div>';
    echo '</div>';
}

echo $OUTPUT->footer();