<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.

namespace local_cohortsync;

defined('MOODLE_INTERNAL') || die();

/**
 * Event observers for local_cohortsync.
 */
class observer {

    /**
     * When a user is unenrolled from a course, remove them from the category cohorts
     * if they are no longer enrolled in any other course in that category.
     */
    public static function user_unenrolled(\core\event\user_enrolment_deleted $event): void {
        global $CFG, $DB;

        // Log inicial.
        error_log("🎯 COHORTSYNC: Evento de desmatrícula detectado");

        try {
            $data = $event->get_data();
            $courseid = (int)($data['courseid'] ?? 0);
            $userid = (int)($data['relateduserid'] ?? 0);

            error_log("📝 Dados: User $userid, Course $courseid");

            if (empty($courseid) || empty($userid)) {
                return;
            }

            // 1. Buscar curso.
            $course = $DB->get_record('course', ['id' => $courseid]);
            if (!$course) {
                error_log("❌ Curso $courseid não existe");
                return;
            }

            error_log("📚 Curso: {$course->fullname}, Categoria: {$course->category}");

            // 2. Buscar contexto da categoria.
            $categorycontext = \context_coursecat::instance((int)$course->category);

            // 3. Buscar coortes da categoria.
            $cohorts = $DB->get_records_sql(
                "SELECT co.id, co.name
                   FROM {cohort} co
                  WHERE co.contextid = ?",
                [$categorycontext->id]
            );

            error_log("👥 Coortes na categoria: " . count($cohorts));

            if (empty($cohorts)) {
                error_log("ℹ️ Nenhum coorte nesta categoria");
                return;
            }

            require_once($CFG->dirroot . '/cohort/lib.php');

            // 4. Processar cada coorte.
            foreach ($cohorts as $cohort) {
                // Verificar se usuário é membro.
                $ismember = $DB->record_exists('cohort_members', [
                    'cohortid' => (int)$cohort->id,
                    'userid' => $userid,
                ]);

                error_log("🔍 Coorte '{$cohort->name}': Membro = " . ($ismember ? 'Sim' : 'Não'));

                if (!$ismember) {
                    continue;
                }

                // Verificar se está em outros cursos da mesma categoria.
                $othercourses = (int)$DB->count_records_sql(
                    "SELECT COUNT(DISTINCT c.id)
                       FROM {course} c
                       JOIN {enrol} e ON e.courseid = c.id
                       JOIN {user_enrolments} ue ON ue.enrolid = e.id
                      WHERE c.category = ?
                        AND ue.userid = ?
                        AND ue.status = 0
                        AND c.id != ?",
                    [(int)$course->category, $userid, $courseid]
                );

                error_log("📊 Cursos restantes na categoria: $othercourses");

                if ($othercourses === 0) {
                    // Remover do coorte (API oficial).
                    \cohort_remove_member((int)$cohort->id, $userid);
                    error_log("✅ REMOVIDO do coorte '{$cohort->name}'");
                } else {
                    error_log("🔒 MANTIDO no coorte (ainda em $othercourses cursos)");
                }
            }

            error_log("🎊 Processamento de desmatrícula concluído");

        } catch (\Throwable $e) {
            error_log("💥 ERRO GRAVE: " . $e->getMessage());
        }
    }

    /**
     * Gets the effective theme for a cohort, prioritising the plugin configuration.
     */
    public static function get_cohort_theme(int $cohortid): ?string {
        global $DB;

        // 1. Plugin config has top priority.
        $plugintheme = $DB->get_field('local_cohortsync_config', 'theme', ['cohortid' => $cohortid]);
        if (!empty($plugintheme)) {
            return (string)$plugintheme;
        }

        // 2. Cohort native theme field (if present).
        try {
            $cohorttheme = $DB->get_field('cohort', 'theme', ['id' => $cohortid]);
            if (!empty($cohorttheme)) {
                return (string)$cohorttheme;
            }
        } catch (\Throwable $e) {
            // Ignore if the field does not exist in the current Moodle version.
        }

        return null;
    }


    /**
     * Apply the plugin configured theme (per cohort) as a SESSION theme on login.
     *
     * IMPORTANT: this does NOT change cohort.theme. When the plugin theme is empty, Moodle will use its normal
     * theme resolution order.
     */
    public static function user_loggedin(\core\event\user_loggedin $event): void {
        global $DB, $SESSION;

        $userid = (int)$event->userid;

        // Do not force themes for admins.
        if (empty($userid) || \is_siteadmin($userid)) {
            if (!empty($SESSION->local_cohortsync_theme)) {
                unset($SESSION->theme);
                unset($SESSION->local_cohortsync_theme);
                unset($SESSION->themerev);
            }
            return;
        }

        // Fetch all plugin themes for cohorts this user belongs to.
        $themes = $DB->get_fieldset_sql(
            "SELECT DISTINCT cc.theme
               FROM {local_cohortsync_config} cc
               JOIN {cohort_members} cm ON cm.cohortid = cc.cohortid
              WHERE cm.userid = ?
                AND cc.theme IS NOT NULL
                AND cc.theme <> ''",
            [$userid]
        );

        $themes = array_values(array_unique(array_filter(array_map('strval', $themes))));

        if (count($themes) === 1) {
            $theme = $themes[0];

            // Validate theme exists.
            $availablethemes = \core_component::get_plugin_list('theme');
            if (!array_key_exists($theme, $availablethemes)) {
                if (!empty($SESSION->local_cohortsync_theme)) {
                    unset($SESSION->theme);
                    unset($SESSION->local_cohortsync_theme);
                    unset($SESSION->themerev);
                }
                return;
            }

            $SESSION->theme = $theme;
            $SESSION->local_cohortsync_theme = $theme;
            // Force reload on next request.
            $SESSION->themerev = time();
            return;
        }

        // None or conflicting plugin themes: remove only if this plugin set a theme earlier.
        if (!empty($SESSION->local_cohortsync_theme)) {
            unset($SESSION->theme);
            unset($SESSION->local_cohortsync_theme);
            unset($SESSION->themerev);
        }
    }

    /**
     * When a user is enrolled into a course, add them to all cohorts in the course category.
     */
    public static function user_enrolled(\core\event\user_enrolment_created $event): void {
        global $CFG, $DB;

        error_log("🎯 COHORTSYNC: Evento de matrícula detectado");

        try {
            $data = $event->get_data();
            $courseid = (int)($data['courseid'] ?? 0);
            $userid = (int)($data['relateduserid'] ?? 0);

            if (empty($courseid) || empty($userid)) {
                return;
            }

            // Não processar admins.
            if (\is_siteadmin($userid)) {
                error_log("🚫 Usuário $userid é admin, ignorando");
                return;
            }

            // Buscar curso.
            $course = $DB->get_record('course', ['id' => $courseid]);
            if (!$course) {
                return;
            }

            // Buscar contexto da categoria.
            $categorycontext = \context_coursecat::instance((int)$course->category);

            // Buscar coortes da categoria.
            $cohorts = $DB->get_records('cohort', ['contextid' => $categorycontext->id]);
            if (empty($cohorts)) {
                return;
            }

            require_once($CFG->dirroot . '/cohort/lib.php');

            foreach ($cohorts as $cohort) {
                $ismember = $DB->record_exists('cohort_members', [
                    'cohortid' => (int)$cohort->id,
                    'userid' => $userid,
                ]);

                if ($ismember) {
                    continue;
                }

                // Adicionar ao coorte (API oficial).
                \cohort_add_member((int)$cohort->id, $userid);
                error_log("✅ ADICIONADO ao coorte '{$cohort->name}'");
            }

        } catch (\Throwable $e) {
            error_log("💥 ERRO na matrícula: " . $e->getMessage());
        }
    }
}
