<?php
require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/cohort/lib.php');

admin_externalpage_setup('local_cohortsync_cleanup');
$PAGE->set_title('Limpeza de Coortes');
$PAGE->set_heading('Limpeza de Coortes');

echo $OUTPUT->header();
echo $OUTPUT->heading('Limpeza de Usuários em Coortes');

// Função para remover usuários que não estão mais matriculados
function local_cohortsync_cleanup_orphaned_users() {
    global $DB;
    
    $removed_total = 0;
    
    // Buscar todos os coortes com temas configurados
    $cohorts = $DB->get_records_sql("
        SELECT c.id, c.name, c.contextid
        FROM {cohort} c
        JOIN {local_cohortsync_config} lc ON lc.cohortid = c.id
        WHERE lc.theme IS NOT NULL AND lc.theme != ''
    ");
    
    foreach ($cohorts as $cohort) {
        $context = context::instance_by_id($cohort->contextid);
        
        if ($context->contextlevel == CONTEXT_COURSECAT) {
            $category_id = $context->instanceid;
            
            // Buscar membros do coorte
            $members = $DB->get_records_sql("
                SELECT cm.userid, u.username
                FROM {cohort_members} cm
                JOIN {user} u ON u.id = cm.userid
                WHERE cm.cohortid = ? AND u.deleted = 0
            ", [$cohort->id]);
            
            $removed_from_cohort = 0;
            
            foreach ($members as $member) {
                // Verificar se usuário ainda está matriculado em cursos da categoria
                $still_enrolled = $DB->count_records_sql("
                    SELECT COUNT(DISTINCT c.id)
                    FROM {course} c
                    JOIN {enrol} e ON e.courseid = c.id
                    JOIN {user_enrolments} ue ON ue.enrolid = e.id
                    WHERE c.category = ?
                    AND ue.userid = ?
                    AND ue.status = 0
                ", [$category_id, $member->userid]) > 0;
                
                if (!$still_enrolled) {
                    // Remover usuário do coorte
                    cohort_remove_member($cohort->id, $member->userid);
                    $removed_from_cohort++;
                    $removed_total++;
                }
            }
            
            if ($removed_from_cohort > 0) {
                echo "<div class='alert alert-warning'>";
                echo "Coorte <strong>{$cohort->name}</strong>: $removed_from_cohort usuário(s) removido(s)";
                echo "</div>";
            }
        }
    }
    
    return $removed_total;
}

if (isset($_POST['cleanup']) && confirm_sesskey()) {
    echo '<div class="alert alert-info">Executando limpeza de coortes...</div>';
    
    $removed = local_cohortsync_cleanup_orphaned_users();
    
    echo '<div class="alert alert-success">';
    echo "<strong>✅ Limpeza concluída!</strong><br>";
    echo "Usuários removidos: <strong>$removed</strong>";
    echo '</div>';
}

echo '<div class="card">';
echo '<div class="card-body">';
echo '<h5 class="card-title">Ferramenta de Limpeza</h5>';
echo '<p class="card-text">Remove automaticamente usuários que não estão mais matriculados em cursos das categorias dos coortes.</p>';
echo '<div class="alert alert-warning">';
echo '<strong>⚠️ Atenção:</strong> Esta ação é automática quando usuários são desmatriculados. Use apenas para limpeza manual.';
echo '</div>';
echo '<form method="post">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '" />';
echo '<button type="submit" name="cleanup" value="1" class="btn btn-warning">🧹 Executar Limpeza Manual</button>';
echo '</form>';
echo '</div>';
echo '</div>';

echo $OUTPUT->footer();