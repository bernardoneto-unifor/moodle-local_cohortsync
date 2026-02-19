<?php
require_once('../../config.php');
require_admin();

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/cohortsync/debug_removal.php');
$PAGE->set_title('Debug Remoção');
$PAGE->set_heading('Debug da Remoção Automática');

echo $OUTPUT->header();
echo '<h2>Debug da Remoção de Usuários</h2>';

// Função para testar a remoção manualmente
function testar_remocao_manual($userid, $courseid) {
    global $DB;
    
    echo "<div class='alert alert-info'>";
    echo "<strong>Testando remoção manual:</strong><br>";
    echo "Usuário ID: $userid<br>";
    echo "Curso ID: $courseid<br>";
    
    try {
        // 1. Buscar curso
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            echo "❌ Curso não encontrado<br>";
            return false;
        }
        echo "✅ Curso: {$course->fullname} (Categoria: {$course->category})<br>";
        
        // 2. Buscar contexto da categoria
        $context = context_coursecat::instance($course->category);
        echo "✅ Contexto da categoria: {$context->id}<br>";
        
        // 3. Buscar coortes da categoria com temas
        $cohorts = $DB->get_records_sql("
            SELECT co.id, co.name 
            FROM {cohort} co
            JOIN {local_cohortsync_config} lc ON lc.cohortid = co.id
            WHERE co.contextid = ? 
            AND lc.theme IS NOT NULL 
            AND lc.theme != ''
        ", [$context->id]);
        
        echo "✅ Coortes com tema: " . count($cohorts) . "<br>";
        
        if (empty($cohorts)) {
            echo "ℹ️ Nenhum coorte com tema configurado<br>";
            return false;
        }
        
        $removidos = 0;
        foreach ($cohorts as $cohort) {
            echo "<br><strong>Processando coorte: {$cohort->name}</strong><br>";
            
            // 4. Verificar se é membro
            $eh_membro = $DB->record_exists('cohort_members', [
                'cohortid' => $cohort->id, 
                'userid' => $userid
            ]);
            
            echo "É membro: " . ($eh_membro ? '✅ Sim' : '❌ Não') . "<br>";
            
            if ($eh_membro) {
                // 5. Verificar outros cursos na categoria
                $outros_cursos = $DB->count_records_sql("
                    SELECT COUNT(DISTINCT c.id)
                    FROM {course} c
                    JOIN {enrol} e ON e.courseid = c.id
                    JOIN {user_enrolments} ue ON ue.enrolid = e.id
                    WHERE c.category = ?
                    AND ue.userid = ?
                    AND ue.status = 0
                    AND c.id != ?
                ", [$course->category, $userid, $courseid]);
                
                echo "Outros cursos na categoria: $outros_cursos<br>";
                
                if ($outros_cursos == 0) {
                    // 6. REMOVER
                    cohort_remove_member($cohort->id, $userid);
                    echo "🚀 <strong>REMOVIDO do coorte!</strong><br>";
                    $removidos++;
                } else {
                    echo "⚠️ Mantido no coorte (ainda em $outros_cursos cursos)<br>";
                }
            }
        }
        
        echo "<br><strong>Resultado: $removidos usuário(s) removido(s)</strong><br>";
        return $removidos > 0;
        
    } catch (Exception $e) {
        echo "❌ Erro: " . $e->getMessage() . "<br>";
        return false;
    }
    
    echo "</div>";
}

// Verificar eventos recentes de desmatrícula
echo '<h3>Eventos Recentes de Desmatrícula</h3>';
$recent_unenrolments = $DB->get_records_sql("
    SELECT l.*, c.shortname as course_name, u.username 
    FROM {logstore_standard_log} l
    LEFT JOIN {course} c ON c.id = l.courseid
    LEFT JOIN {user} u ON u.id = l.relateduserid
    WHERE l.eventname = '\\\\core\\\\event\\\\user_enrolment_deleted'
    ORDER BY l.timecreated DESC 
    LIMIT 10
");

if ($recent_unenrolments) {
    echo '<table class="table table-sm table-bordered">';
    echo '<tr><th>Data</th><th>Curso</th><th>Usuário</th><th>Ação</th></tr>';
    foreach ($recent_unenrolments as $log) {
        echo '<tr>';
        echo '<td>' . userdate($log->timecreated, '%d/%m/%Y %H:%M') . '</td>';
        echo '<td>' . ($log->course_name ?: 'N/A') . ' (ID: ' . $log->courseid . ')</td>';
        echo '<td>' . ($log->username ?: 'ID: ' . $log->relateduserid) . '</td>';
        echo '<td><a href="#" onclick="testarRemocao(' . $log->courseid . ',' . $log->relateduserid . ')">Testar</a></td>';
        echo '</tr>';
    }
    echo '</table>';
} else {
    echo "Nenhum evento de desmatrícula recente encontrado.<br>";
}

// Formulário de teste manual
echo '<h3>Teste Manual de Remoção</h3>';
echo '<div class="card">';
echo '<div class="card-body">';
echo '<form method="post" id="testForm">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '" />';
echo '<div class="row">';
echo '<div class="col-md-5">';
echo '<div class="form-group">';
echo '<label>ID do Usuário:</label>';
echo '<input type="number" name="userid" class="form-control" value="3" required />';
echo '</div>';
echo '</div>';
echo '<div class="col-md-5">';
echo '<div class="form-group">';
echo '<label>ID do Curso:</label>';
echo '<input type="number" name="courseid" class="form-control" value="2" required />';
echo '</div>';
echo '</div>';
echo '<div class="col-md-2">';
echo '<div class="form-group">';
echo '<label>&nbsp;</label><br>';
echo '<button type="submit" name="testar" class="btn btn-danger">Testar Remoção</button>';
echo '</div>';
echo '</div>';
echo '</div>';
echo '</form>';
echo '</div>';
echo '</div>';

if (isset($_POST['testar'])) {
    testar_remocao_manual($_POST['userid'], $_POST['courseid']);
}

// Verificar observers registrados
echo '<h3>Observers Registrados</h3>';
try {
    $observers = $DB->get_records('task_observers');
    $our_observers = array_filter($observers, function($observer) {
        return strpos($observer->callback, 'local_cohortsync') !== false;
    });
    
    echo "Observers do nosso plugin: <strong>" . count($our_observers) . "</strong><br>";
    
    foreach ($our_observers as $observer) {
        $event_type = strpos($observer->eventname, 'deleted') !== false ? '🗑️ DESMATRÍCULA' : '📥 MATRÍCULA';
        echo "$event_type: {$observer->eventname}<br>";
    }
} catch (Exception $e) {
    echo "❌ Erro ao buscar observers: " . $e->getMessage() . "<br>";
}

echo $OUTPUT->footer();
?>

<script>
function testarRemocao(courseid, userid) {
    if (confirm('Testar remoção para curso ' + courseid + ' e usuário ' + userid + '?')) {
        document.querySelector('input[name="courseid"]').value = courseid;
        document.querySelector('input[name="userid"]').value = userid;
        document.querySelector('button[name="testar"]').click();
    }
}
</script>