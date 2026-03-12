<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

/**
 * Manual cohort synchronisation admin page.
 *
 * @package    local_cohortsync
 * @copyright  2026 Bernardo Neto <bernardoneto@unifor.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortsync;

defined('MOODLE_INTERNAL') || die();

/**
 * Event observers for local_cohortsync.
 */
class observer {

    /**
     * Whether the plugin's observer logic is enabled.
     */
    protected static function enabled(): bool {
        return (bool)get_config('local_cohortsync', 'enableobservers', 1);
    }

    /**
     * When a user is enrolled into a course, queue an ad-hoc task to add them to the
     * managed cohorts of the course category.
     */
    public static function user_enrolled(\core\event\user_enrolment_created $event): void {
        if (!self::enabled()) {
            return;
        }

        $data = $event->get_data();
        $courseid = (int)($data['courseid'] ?? 0);
        $userid = (int)($data['relateduserid'] ?? 0);

        if (empty($courseid) || empty($userid) || \is_siteadmin($userid)) {
            return;
        }

        $task = new \local_cohortsync\task\process_enrolment();
        $task->set_custom_data([
            'courseid' => $courseid,
            'userid' => $userid,
        ]);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * When a user is unenrolled from a course, queue an ad-hoc task to remove them from
     * the managed cohorts of the course category (only if they are no longer enrolled
     * in any other course in that category).
     */
    public static function user_unenrolled(\core\event\user_enrolment_deleted $event): void {
        if (!self::enabled()) {
            return;
        }

        $data = $event->get_data();
        $courseid = (int)($data['courseid'] ?? 0);
        $userid = (int)($data['relateduserid'] ?? 0);

        if (empty($courseid) || empty($userid) || \is_siteadmin($userid)) {
            return;
        }

        $task = new \local_cohortsync\task\process_unenrolment();
        $task->set_custom_data([
            'courseid' => $courseid,
            'userid' => $userid,
        ]);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Apply the plugin configured theme (per cohort) as a SESSION theme on login.
     *
     * This does NOT change cohort.theme; it only sets a user session theme.
     */
    public static function user_loggedin(\core\event\user_loggedin $event): void {
        global $DB, $SESSION;

        if (!self::enabled()) {
            return;
        }

        $userid = (int)$event->userid;
        $cache = \cache::make('local_cohortsync', 'sessiontheme');
        $forcedtheme = $cache->get('forcedtheme');

        // Never force themes for admins.
        if (empty($userid) || \is_siteadmin($userid)) {
            if (!empty($forcedtheme)) {
                unset($SESSION->theme);
                unset($SESSION->themerev);
                $cache->delete('forcedtheme');
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
        $defaulttheme = (string)get_config('local_cohortsync', 'defaulttheme', '');

        $wanttheme = null;
        if (count($themes) === 1) {
            $wanttheme = $themes[0];
        } else if ($defaulttheme !== '') {
            $wanttheme = $defaulttheme;
        }

        $availablethemes = \core_component::get_plugin_list('theme');

        if (!empty($wanttheme) && array_key_exists($wanttheme, $availablethemes)) {
            $SESSION->theme = $wanttheme;
            $SESSION->themerev = time();
            $cache->set('forcedtheme', $wanttheme);
            return;
        }

        // None / conflicting / invalid: revert only if this plugin set it earlier.
        if (!empty($forcedtheme)) {
            unset($SESSION->theme);
            unset($SESSION->themerev);
            $cache->delete('forcedtheme');
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
}
