<?php
require_once('../../config.php');
require_admin();

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/cohortsync/debug_simple_fix.php');
$PAGE->set_title('Debug Simplificado');
$PAGE->set_heading('Debug Simplificado - Foco na Remoção');

echo $OUTPUT->header();
echo '<h2>Debug da Remoção Automática</h2>';

// 1. Verificar arquivos essenciais
echo "<h3>1. Verificação de Arquivos</h3>";
$essential_files = [
    'db/events.php' => 'Arquivo de eventos (OBSERVERS)',
    'classes/observer.php' => 'Classe do observer',
    'version.php' => 'Versão do plugin'
];

foreach ($essential_files as $file => $desc) {
    $exists = file_exists(__DIR__ . '/' . $file);
    echo ($exists ? '✅' : '❌') . " $desc: $file<br>";
}

// 2. Teste PRÁTICO de remoção
echo "<h3>2. Teste Prático de Remoção</h3>";

function testar_remocao_simples($userid, $courseid) {
    global $DB;
    
    echo "<div class='border p-3 mb-3'>";
    echo "<strong>Teste de Remoção:</strong> User $userid, Course $courseid<br>";
    
    try {
        // Buscar curso
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            echo "❌ Curso não encontrado<br>";
            return false;
        }
        echo "✅ Curso: {$course->fullname} (Categoria: {$course->category})<br>";
        
        // Buscar coortes da categoria
        $context = context_coursecat::instance($course->category);
        $cohorts = $DB->get_records_sql("
            SELECT co.id, co.name 
            FROM {cohort} co
            LEFT JOIN {local_cohortsync_config} lc ON lc.cohortid = co.id
            WHERE co.contextid = ? 
            AND lc.theme IS NOT NULL 
        ", [$context->id]);
        
        echo "✅ Coortes com tema: " . count($cohorts) . "<br>";
        
        $resultado = "";
        foreach ($cohorts as $cohort) {
            $eh_membro = $DB->record_exists('cohort_members', [
                'cohortid' => $cohort->id, 
                'userid' => $userid
            ]);
            
            $resultado .= "Coorte <strong>{$cohort->name}</strong>: ";
            
            if ($eh_membro) {
                // Verificar outros cursos
                $outros_cursos = $DB->count_records_sql("
                    SELECT COUNT(*) 
                    FROM {user_enrolments} ue 
                    JOIN {enrol} e ON e.id = ue.enrolid 
                    JOIN {course} c ON c.id = e.courseid 
                    WHERE c.category = ? AND ue.userid = ? AND c.id != ?
                ", [$course->category, $userid, $courseid]);
                
                if ($outros_cursos == 0) {
                    cohort_remove_member($cohort->id, $userid);
                    $resultado .= "🚀 <span style='color:green'>REMOVIDO</span><br>";
                } else {
                    $resultado .= "⚠️ Mantido ($outros_cursos outros cursos)<br>";
                }
            } else {
                $resultado .= "❌ Não era membro<br>";
            }
        }
        
        echo $resultado;
        echo "</div>";
        return true;
        
    } catch (Exception $e) {
        echo "❌ Erro: " . $e->getMessage() . "<br>";
        return false;
    }
}

// Formulário de teste
echo '<div class="card">';
echo '<div class="card-body">';
echo '<form method="post">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '" />';
echo '<div class="form-group">';
echo '<label>ID do Usuário para TESTE:</label>';
echo '<input type="number" name="userid" class="form-control" value="3" required />';
echo '</div>';
echo '<div class="form-group">';
echo '<label>ID do Curso para TESTE:</label>';
echo '<input type="number" name="courseid" class="form-control" value="2" required />';
echo '</div>';
echo '<button type="submit" name="testar" class="btn btn-danger">Testar Remoção Agora</button>';
echo '</form>';
echo '</div>';
echo '</div>';

if (isset($_POST['testar'])) {
    testar_remocao_simples($_POST['userid'], $_POST['courseid']);
}

// 3. Verificar se o observer está funcionando
echo "<h3>3. Verificação do Observer</h3>";

// Método SIMPLES para verificar observers
$events_file = __DIR__ . '/db/events.php';
if (file_exists($events_file)) {
    $events_content = file_get_contents($events_file);
    if (strpos($events_content, 'user_enrolment_deleted') !== false) {
        echo "✅ Observer de desmatrícula configurado<br>";
    } else {
        echo "❌ Observer de desmatrícula NÃO configurado<br>";
    }
    
    if (strpos($events_content, 'user_enrolment_created') !== false) {
        echo "✅ Observer de matrícula configurado<br>";
    } else {
        echo "❌ Observer de matrícula NÃO configurado<br>";
    }
}

echo $OUTPUT->footer();