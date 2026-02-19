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

namespace local_cohortsync\privacy;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy Subsystem implementation for local_cohortsync.
 *
 * This plugin stores configuration per cohort, but it does not store any
 * personal user data.
 */
class provider implements \core_privacy\local\metadata\null_provider {

    /**
     * Returns the language string identifier explaining that the plugin stores no personal data.
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
