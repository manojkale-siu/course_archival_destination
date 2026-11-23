<?php
// Backup.php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

require_login();

header('Content-Type: application/json');

$courseid = required_param('courseid', PARAM_INT);

$course = $DB->get_record(
    'course',
    ['id' => $courseid],
    '*',
    MUST_EXIST
);

$context = context_course::instance($courseid);

require_capability(
    'moodle/backup:backupcourse',
    $context
);

$bc = null;

try {

    /*
     * Create a normal/general Moodle course backup.
     */
    $bc = new backup_controller(
        backup::TYPE_1COURSE,
        $courseid,
        backup::FORMAT_MOODLE,
        backup::INTERACTIVE_NO,
        backup::MODE_GENERAL,
        $USER->id
    );

    $plan = $bc->get_plan();

    /*
     * These are the settings required for our migration backup.
     */
    $settings = [
        'users' => true,
        'anonymize' => false,
        'role_assignments' => true,
        'activities' => true,
        'blocks' => true,
        'files' => true,
        'filters' => true,
        'comments' => true,
        'badges' => true,
        'calendarevents' => true,
        'userscompletion' => true,
        'logs' => true,
        'grade_histories' => true,
        'questionbank' => true,
        'groups' => true,
        'competencies' => true,
        'customfield' => true,
        'contentbankcontent' => true,
        'xapistate' => true,
        'legacyfiles' => true,
    ];

    /*
     * Force the required settings.
     */
    foreach ($settings as $name => $value) {

        if (!$plan->setting_exists($name)) {
            continue;
        }

        $setting = $plan->get_setting($name);

        /*
         * Make sure a site-level backup lock does not
         * prevent Course Migrator from applying its
         * migration-specific setting.
         */
        if ($setting->get_status() !== base_setting::NOT_LOCKED) {
            $setting->set_status(base_setting::NOT_LOCKED);
        }

        $setting->set_value($value);

        /*
         * Lock it for the remainder of this backup operation.
         */
        $setting->set_status(base_setting::LOCKED_BY_CONFIG);
    }

    /*
     * Build a readable backup filename.
     */
    // $filename = clean_filename(
    //     $course->shortname . '-' . date('Ymd-His') . '.mbz'
    // );
    
    
    
    // ---------------------------------------------------------
    // Build source category hierarchy for metadata
    // ---------------------------------------------------------
    
    $categorypath = [];
    $categoryid = (int)$course->category;
    
    while ($categoryid > 0) {
        $category = $DB->get_record(
            'course_categories',
            ['id' => $categoryid],
            'id,name,parent',
            MUST_EXIST
        );
    
        array_unshift($categorypath, $category);
        $categoryid = (int)$category->parent;
    }
    
    $categorynames = array_map(
        function($category) {
            return trim($category->name);
        },
        $categorypath
    );
    
    // Expected hierarchy:
    // Institute → Program → Batch → Semester → Specialization
    
    $institute      = $categorynames[0] ?? '';
    $program        = $categorynames[1] ?? '';
    $batch          = $categorynames[2] ?? '';
    $semester       = $categorynames[3] ?? '';
    $specialization = $categorynames[4] ?? '';
    
    
    // ---------------------------------------------------------
    // Human-readable MBZ filename
    // ---------------------------------------------------------
    
    $filenameparts = array_filter([
        $institute,
        $program,
        $batch,
        $semester,
        $specialization,
        $course->shortname ?: $course->fullname,
        date('Ymd-His'),
    ]);
    
    $filenamebase = implode('-', array_map(
        static function($value) {
            $value = trim($value);
    
            // Convert spaces/special characters to hyphens.
            $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value);
    
            return trim($value, '-_.');
        },
        $filenameparts
    ));
    
    // Keep filename at a reasonable length.
    $filenamebase = substr($filenamebase, 0, 180);
    
    $filename = clean_filename($filenamebase . '.mbz');
    
    // ENDS HERE NEW CODE 
    // ===================================================================================
    

    $plan->get_setting('filename')->set_value($filename);

    /*
     * Make sure the backup includes physical files.
     *
     * For MODE_GENERAL this should normally already be true,
     * but we verify it before executing the plan.
     */
    if (!$bc->get_include_files()) {
        throw new moodle_exception(
            'Course Migrator could not enable file inclusion for this backup.'
        );
    }

    /*
     * Execute the backup.
     */
    $bc->execute_plan();

    /*
     * Get generated MBZ.
     */
    $results = $bc->get_results();

    if (
        !$results ||
        !isset($results['backup_destination'])
    ) {
        throw new moodle_exception(
            'Backup did not produce a result.'
        );
    }

    $file = $results['backup_destination'];

    if (!$file instanceof stored_file) {
        throw new moodle_exception(
            'Backup file was not created.'
        );
    }

    /*
     * Save MBZ to Moodle temporary directory.
     */
    $tempdir = make_temp_directory('coursemigrator');

    $filepath = $tempdir . DIRECTORY_SEPARATOR . $filename;

    if (!$file->copy_content_to($filepath)) {
        throw new moodle_exception(
            'Failed to copy backup file to temporary storage.'
        );
    }

    /*
     * Return useful information for testing.
     */
    echo json_encode([
        'success' => true,

        'courseid' => $courseid,

        'coursename' => $course->fullname,

        'shortname' => $course->shortname,

        'filename' => $filename,

        'filepath' => $filepath,

        'files_included' => (bool)$bc->get_include_files(),

        'backup_settings' => [
            'users' => true,
            'anonymize' => false,
            'role_assignments' => true,
            'activities' => true,
            'blocks' => true,
            'files' => true,
            'filters' => true,
            'comments' => true,
            'badges' => true,
            'calendarevents' => true,
            'userscompletion' => true,
            'logs' => true,
            'grade_histories' => true,
            'questionbank' => true,
            'groups' => true,
            'competencies' => true,
            'customfield' => true,
            'contentbankcontent' => true,
            'xapistate' => true,
            'legacyfiles' => true,
        ],
        
        'metadata' => [
            'source' => [
                'moodle_url' => $CFG->wwwroot,
                'course_id' => (int)$course->id,
                'category_id' => (int)$course->category,
            ],
        
            'course' => [
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
            ],
        
            'hierarchy' => [
                'institute' => $institute,
                'program' => $program,
                'batch' => $batch,
                'semester' => $semester,
                'specialization' => $specialization,
        
                // Complete original category path.
                'path' => $categorynames,
            ],
        
            'backup' => [
                'filename' => $filename,
                'created_at' => date('c'),
                'files_included' => (bool)$bc->get_include_files(),
            ],
        ],
        
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'courseid' => $courseid,
        'message' => $e->getMessage(),
    ]);

} finally {

    if ($bc) {
        $bc->destroy();
    }
}