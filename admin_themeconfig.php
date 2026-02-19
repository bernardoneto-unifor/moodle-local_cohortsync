<?php
require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// -----------------------------------------------------------------------------
// Página admin
// -----------------------------------------------------------------------------
admin_externalpage_setup('local_cohortsync_themeconfig');
$PAGE->set_title(get_string('pluginname', 'local_cohortsync') . ' - Configurar Temas');
$PAGE->set_heading(get_string('pluginname', 'local_cohortsync'));

echo $OUTPUT->header();
echo $OUTPUT->heading('Configurar Temas por Coorte');

// Lista de temas disponíveis (para validação e para o select).
$available_themes = core_component::get_plugin_list('theme');
ksort($available_themes);

// Aviso: temas por coorte desativados no site.
if (empty($CFG->allowcohortthemes)) {
    \core\notification::warning(
        '⚠️ As <strong>temas por coorte</strong> estão desativadas no site. ' .
        'Ative em Administração do site → Aparência → Temas → Configurações avançadas de tema → "Allow cohort themes".'
    );
}

\core\notification::info(
    '💡 <strong>Importante:</strong> o "Tema (Plugin)" é aplicado como <strong>tema de sessão</strong> no login e ' .
    '<strong>não altera</strong> o tema configurado no coorte. Para o usuário ver a mudança, ele precisa ' .
    '<strong>sair e entrar novamente</strong>.'
);

// -----------------------------------------------------------------------------
// Processar formulário (salva APENAS a tabela do plugin)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $themes = isset($_POST['theme']) ? $_POST['theme'] : [];
    $saved = 0;

    foreach ($themes as $cohortid => $theme) {
        $cohortid = (int)$cohortid;
        $theme = trim((string)$theme);

        if ($cohortid <= 0) {
            continue;
        }

        if ($theme !== '') {
            // Validação básica: tema existe?
            if (!array_key_exists($theme, $available_themes)) {
                \core\notification::warning('Tema inválido ignorado para o coorte ID ' . $cohortid . ': ' . s($theme));
                continue;
            }

            // Inserir / atualizar config do plugin.
            $existing = $DB->get_record('local_cohortsync_config', ['cohortid' => $cohortid]);

            if ($existing) {
                $existing->theme = $theme;
                $existing->timemodified = time();
                $DB->update_record('local_cohortsync_config', $existing);
            } else {
                $new = new stdClass();
                $new->cohortid = $cohortid;
                $new->theme = $theme;
                $new->timecreated = time();
                $new->timemodified = time();
                $DB->insert_record('local_cohortsync_config', $new);
            }

            $saved++;
        } else {
            // "-- Usar tema do coorte --" => remover override do plugin.
            $DB->delete_records('local_cohortsync_config', ['cohortid' => $cohortid]);
        }
    }

    \core\notification::success("✅ $saved configuração(ões) de tema salva(s) com sucesso!");
}

// -----------------------------------------------------------------------------
// Buscar temas de ambas as fontes (coorte nativo + plugin)
// -----------------------------------------------------------------------------
$cohorts_with_themes = $DB->get_records_sql("
    SELECT
        c.id,
        c.name,
        c.idnumber,
        c.theme AS cohort_theme,
        cc.theme AS plugin_theme,
        cc.timemodified AS plugin_modified,
        (SELECT COUNT(*) FROM {cohort_members} cm WHERE cm.cohortid = c.id) AS member_count,
        CASE
            WHEN cc.theme IS NOT NULL AND cc.theme <> '' THEN cc.theme
            WHEN c.theme IS NOT NULL AND c.theme <> '' THEN c.theme
            ELSE NULL
        END AS effective_theme,
        CASE
            WHEN cc.theme IS NOT NULL AND cc.theme <> '' THEN 'plugin'
            WHEN c.theme IS NOT NULL AND c.theme <> '' THEN 'cohort'
            ELSE 'none'
        END AS theme_source
    FROM {cohort} c
    LEFT JOIN {local_cohortsync_config} cc ON cc.cohortid = c.id
    ORDER BY c.name ASC
");

// Estatísticas.
$total_cohorts = count($cohorts_with_themes);
$cohorts_with_theme = 0;
$cohorts_with_cohort_theme = 0;
$cohorts_with_plugin_theme = 0;

foreach ($cohorts_with_themes as $cohort) {
    if (!empty($cohort->effective_theme)) {
        $cohorts_with_theme++;
        if ($cohort->theme_source === 'cohort') {
            $cohorts_with_cohort_theme++;
        } else if ($cohort->theme_source === 'plugin') {
            $cohorts_with_plugin_theme++;
        }
    }
}

echo '<div class="card mb-4">';
echo '<div class="card-body">';
echo '<h5 class="card-title">📊 Estatísticas de Temas</h5>';
echo '<div class="row">';
echo '<div class="col-md-3 text-center"><div class="alert alert-info"><h4>' . $total_cohorts . '</h4><small>Total de Coortes</small></div></div>';
echo '<div class="col-md-3 text-center"><div class="alert alert-success"><h4>' . $cohorts_with_theme . '</h4><small>Com Tema</small></div></div>';
echo '<div class="col-md-3 text-center"><div class="alert alert-warning"><h4>' . $cohorts_with_cohort_theme . '</h4><small>Configurado no Coorte</small></div></div>';
echo '<div class="col-md-3 text-center"><div class="alert alert-primary"><h4>' . $cohorts_with_plugin_theme . '</h4><small>Override no Plugin</small></div></div>';
echo '</div>';
echo '</div>';
echo '</div>';

echo '<div class="card">';
echo '<div class="card-body">';
echo '<p>Configure o tema para cada coorte. <strong>Se configurado aqui, o plugin aplica como tema de sessão no login</strong> (sem mexer no coorte).</p>';

echo '<form method="post">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '" />';

echo '<table class="table table-bordered table-striped">';
echo '<thead class="thead-dark">';
echo '<tr>';
echo '<th width="25%">Coorte</th>';
echo '<th width="25%">Tema (Plugin)</th>';
echo '<th width="15%" class="text-center">Membros</th>';
echo '<th width="20%" class="text-center">Status/Fonte</th>';
echo '<th width="15%" class="text-center">Tema no Coorte</th>';
echo '</tr>';
echo '</thead>';
echo '<tbody>';

foreach ($cohorts_with_themes as $cohort) {
    $current_plugin_theme = $cohort->plugin_theme;
    $current_cohort_theme = $cohort->cohort_theme;
    $effective_theme = $cohort->effective_theme;
    $theme_source = $cohort->theme_source;
    $member_count = (int)$cohort->member_count;

    echo '<tr>';

    echo '<td>';
    echo '<strong>' . s($cohort->name) . '</strong>';
    if (!empty($cohort->idnumber)) {
        echo '<br><small class="text-muted">ID: ' . s($cohort->idnumber) . '</small>';
    }
    echo '</td>';

    echo '<td>';
    echo '<select name="theme[' . (int)$cohort->id . ']" class="form-control">';
    echo '<option value="">-- Usar tema do coorte --</option>';

    foreach ($available_themes as $theme_name => $theme_path) {
        $selected = ($current_plugin_theme === $theme_name) ? 'selected' : '';
        echo '<option value="' . s($theme_name) . '" ' . $selected . '>' . s($theme_name) . '</option>';
    }

    echo '</select>';

    if (!empty($effective_theme)) {
        echo '<small class="text-success mt-1 d-block">✅ Tema efetivo (plugin/coorte): <strong>' . s($effective_theme) . '</strong></small>';
    }
    echo '</td>';

    echo '<td class="text-center">';
    echo '<span class="badge badge-' . ($member_count > 0 ? 'success' : 'secondary') . '">' . $member_count . '</span>';
    echo '</td>';

    echo '<td class="text-center">';
    if ($theme_source === 'plugin') {
        echo '<span class="badge badge-primary">🔄 Plugin</span><br><small class="text-muted">Tema de sessão (login)</small>';
    } else if ($theme_source === 'cohort') {
        echo '<span class="badge badge-warning">📋 Coorte</span><br><small class="text-muted">Configuração nativa</small>';
    } else {
        echo '<span class="badge badge-secondary">❌ Sem tema</span>';
    }
    echo '</td>';

    echo '<td class="text-center">';
    if (!empty($current_cohort_theme)) {
        echo '<span class="badge badge-info">' . s($current_cohort_theme) . '</span><br><small class="text-muted">Nativo</small>';
    } else {
        echo '<span class="text-muted">--</span>';
    }
    echo '</td>';

    echo '</tr>';
}

echo '</tbody>';
echo '</table>';

echo '<div class="alert alert-info">';
echo '<strong>🎯 Observações</strong><br>';
echo '• Se você configurar um "Tema (Plugin)", ele será aplicado como <strong>tema de sessão</strong> no login (sem alterar o coorte).<br>';
echo '• Para ver a mudança, o usuário precisa <strong>sair e entrar novamente</strong>.<br>';
echo '• Se o plugin estiver em branco, o Moodle pode aplicar o <strong>tema do coorte</strong> (se habilitado) ou o tema padrão do site.';
echo '</div>';

echo '<div class="text-center">';
echo '<button type="submit" class="btn btn-primary btn-lg">💾 Salvar Configurações do Plugin</button>';
echo ' <a href="' . $CFG->wwwroot . '/cohort/index.php" class="btn btn-info">📋 Gerenciar Coortes</a>';
echo ' <a href="' . $CFG->wwwroot . '/admin/category.php?category=localplugins" class="btn btn-secondary">Voltar</a>';
echo '</div>';

echo '</form>';
echo '</div>';
echo '</div>';

// Créditos.
echo '<div class="mt-4 text-center text-muted">';
echo '<hr>';
echo '<small><strong>🔄 Sincronização de Coortes v5.1</strong><br>Desenvolvido por <strong>Bernardo Neto (DTec)</strong></small>';
echo '</div>';

echo $OUTPUT->footer();
