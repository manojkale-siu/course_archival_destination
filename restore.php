<?php

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

$id = required_param('id', PARAM_INT);

require_login();
require_sesskey();
require_capability('moodle/course:create', context_system::instance());

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$logs = [];
$controller = null;
$backuppath = '';
$migration = null;
$newcourseid = 0;

function coursemigrator_json_response(
    bool $success,
    string $message,
    array $extra = []
): void {
    echo json_encode(
        array_merge([
            'success' => $success,
            'message' => $message,
        ], $extra),
        JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function coursemigrator_restore_folder_name(string $name): string {
    $name = trim($name);
    $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', $name);
    $name = preg_replace('/_+/', '_', $name);
    $name = trim($name, '_');

    return $name !== '' ? $name : 'Unknown';
}

function coursemigrator_restore_get_category(
    string $name,
    int $parentid
): stdClass {
    global $DB;

    $category = $DB->get_record(
        'course_categories',
        ['name' => $name, 'parent' => $parentid],
        '*',
        IGNORE_MULTIPLE
    );

    if ($category) {
        return $category;
    }

    $created = core_course_category::create((object)[
        'name' => $name,
        'parent' => $parentid,
        'visible' => 1,
    ]);

    return $DB->get_record(
        'course_categories',
        ['id' => $created->id],
        '*',
        MUST_EXIST
    );
}

function coursemigrator_restore_update(
    int $id,
    string $status,
    string $message,
    array $logs,
    int $courseid = 0
): void {
    global $DB;

    $record = $DB->get_record(
        'course_migrator_receiver',
        ['id' => $id],
        '*',
        MUST_EXIST
    );

    $record->restore_status = $status;
    $record->restore_message = $message;
    $record->restore_logs = json_encode(
        $logs,
        JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    $record->updated_at = time();

    if ($courseid > 0) {
        $course = $DB->get_record(
            'course',
            ['id' => $courseid],
            'id,fullname,shortname',
            MUST_EXIST
        );

        $record->destination_course_id = $course->id;
        $record->destination_course_fullname = $course->fullname;
        $record->destination_course_shortname = $course->shortname;
    }

    $DB->update_record('course_migrator_receiver', $record);
}

try {
    $migration = $DB->get_record(
        'course_migrator_receiver',
        ['id' => $id],
        '*',
        MUST_EXIST
    );

    $institute = trim((string)$migration->institute);
    $program = trim((string)$migration->program);
    $batch = trim((string)$migration->batch);
    $semester = trim((string)$migration->semester);
    $specialization = trim((string)$migration->specialization);

    $filename = clean_filename(
        basename((string)$migration->mbz_filename)
    );

    if (
        $institute === '' ||
        $program === '' ||
        $filename === '' ||
        strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'mbz'
    ) {
        throw new moodle_exception(
            'Invalid migration hierarchy or MBZ filename.'
        );
    }

    // Locate the retained MBZ outside public_html.
    $filepath = dirname($CFG->dirroot)
        . DIRECTORY_SEPARATOR . 'coursemigrator'
        . DIRECTORY_SEPARATOR . coursemigrator_restore_folder_name($institute)
        . DIRECTORY_SEPARATOR . coursemigrator_restore_folder_name($program);

    foreach ([$batch, $semester, $specialization] as $level) {
        if ($level !== '') {
            $filepath .= DIRECTORY_SEPARATOR
                . coursemigrator_restore_folder_name($level);
        }
    }

    $filepath .= DIRECTORY_SEPARATOR . $filename;

    if (!is_file($filepath) || !is_readable($filepath)) {
        throw new moodle_exception(
            'MBZ file not found or not readable on the server.'
        );
    }

    $shortname = trim((string)$migration->source_course_shortname);

    if ($shortname === '') {
        throw new moodle_exception('Source course shortname is missing.');
    }

    // Do not create a duplicate course.
    $existing = $DB->get_record(
        'course',
        ['shortname' => $shortname],
        'id,fullname',
        IGNORE_MULTIPLE
    );

    if ($existing) {
        coursemigrator_restore_update(
            $id,
            'success',
            'Course already exists on the destination.',
            ['Restore skipped because the course already exists.'],
            (int)$existing->id
        );

        coursemigrator_json_response(true, 'Course already exists.', [
            'courseid' => (int)$existing->id,
            'coursename' => $existing->fullname,
            'courseurl' => (new moodle_url(
                '/course/view.php',
                ['id' => $existing->id]
            ))->out(false),
            'alreadyexists' => true,
        ]);
    }

    // Find or create the hierarchy.
    $category = coursemigrator_restore_get_category($institute, 0);
    $category = coursemigrator_restore_get_category(
        $program,
        (int)$category->id
    );

    foreach ([$batch, $semester, $specialization] as $level) {
        if ($level !== '') {
            $category = coursemigrator_restore_get_category(
                $level,
                (int)$category->id
            );
        }
    }

    // Extract the backup into Moodle's temporary backup directory.
    $backupid = restore_controller::get_tempdir_name(SITEID, $USER->id);
    $backuppath = make_backup_temp_directory($backupid);

    $packer = get_file_packer('application/vnd.moodle.backup');

    if (!$packer->extract_to_pathname($filepath, $backuppath)) {
        throw new moodle_exception('Failed to extract the MBZ backup.');
    }

    // Create the destination course.
    $newcourseid = restore_dbops::create_new_course(
        $migration->source_course_fullname,
        $shortname,
        (int)$category->id
    );

    // Restore the course using Moodle's restore API.
    $controller = new restore_controller(
        $backupid,
        $newcourseid,
        backup::INTERACTIVE_NO,
        backup::MODE_GENERAL,
        (int)get_admin()->id,
        backup::TARGET_NEW_COURSE
    );

    $plan = $controller->get_plan();

    if ($plan->setting_exists('users')) {
        $plan->get_setting('users')->set_value(true);
    }

    if ($plan->setting_exists('role_assignments')) {
        $plan->get_setting('role_assignments')->set_value(true);
    }

    // Keep the existing receiver's enrolment policy.
    if ($plan->setting_exists('enrolments')) {
        $plan->get_setting('enrolments')->set_value(backup::ENROL_NEVER);
    }

    $controller->execute_precheck();
    $controller->execute_plan();
    $controller->destroy();
    $controller = null;

    // Verify that the course exists after restore.
    $course = $DB->get_record(
        'course',
        ['id' => $newcourseid],
        'id,fullname,shortname',
        MUST_EXIST
    );

    $logs[] = 'Course restored successfully.';
    $logs[] = 'Destination course ID: ' . $course->id;

    coursemigrator_restore_update(
        $id,
        'success',
        'Course restored successfully.',
        $logs,
        (int)$course->id
    );

    if ($backuppath !== '' && is_dir($backuppath)) {
        fulldelete($backuppath);
    }

    coursemigrator_json_response(true, 'Course restored successfully.', [
        'courseid' => (int)$course->id,
        'coursename' => $course->fullname,
        'courseurl' => (new moodle_url(
            '/course/view.php',
            ['id' => $course->id]
        ))->out(false),
    ]);
    
    //  {
    //     echo "Working till here 2nd...";
    //     exit;
    // }

} catch (Throwable $e) {
    if ($controller !== null) {
        try {
            $controller->destroy();
        } catch (Throwable $ignored) {
            // Preserve the original exception.
        }
    }

    if ($backuppath !== '' && is_dir($backuppath)) {
        fulldelete($backuppath);
    }

    $message = $e->getMessage();
    $logs[] = 'ERROR: ' . $message;

    if ($migration !== null) {
        try {
            coursemigrator_restore_update(
                $id,
                'failed',
                $message,
                $logs,
                $newcourseid
            );
        } catch (Throwable $ignored) {
            // Preserve the original exception.
        }
    }

    debugging(
        'Course Migrator restore error: ' . $message,
        DEBUG_DEVELOPER
    );

    coursemigrator_json_response(false, $message);
}