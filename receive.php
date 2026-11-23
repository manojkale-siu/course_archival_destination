<?php
// destination receive.php 

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once(__DIR__ . '/metadata.php');

header('Content-Type: application/json');

$receive_token = 'AUTH TOEKN FOR COMMUNIATION BETWEEN TO SERVERS';


// =========================================================
// Helper: Return JSON and stop execution.
// =========================================================

function coursemigrator_response($success, $message, $data = []) {

    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);

    exit;
}


// =========================================================
// Helper: Convert hierarchy name to safe folder name.
// =========================================================

function coursemigrator_folder_name(string $name): string
{
    $name = trim($name);

    // Replace spaces and special characters with underscores.
    $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', $name);

    // Remove duplicate underscores.
    $name = preg_replace('/_+/', '_', $name);

    // Trim underscores from beginning and end.
    $name = trim($name, '_');

    return $name !== '' ? $name : 'Unknown';
}

// =========================================================
// Authentication
// =========================================================

$headers = function_exists('getallheaders') ? getallheaders() : [];

$token = '';

if (isset($_SERVER['HTTP_X_COURSE_MIGRATOR_TOKEN'])) {

    $token = $_SERVER['HTTP_X_COURSE_MIGRATOR_TOKEN'];

} else if (isset($headers['X-Course-Migrator-Token'])) {

    $token = $headers['X-Course-Migrator-Token'];

} else if (isset($headers['HTTP_X_COURSE_MIGRATOR_TOKEN'])) {

    $token = $headers['HTTP_X_COURSE_MIGRATOR_TOKEN'];

} else if (isset($_POST['token'])) {

    $token = $_POST['token'];
}


if (!hash_equals($receive_token, $token)) {

    http_response_code(401);

    coursemigrator_response(
        false,
        'Invalid authentication token.'
    );
}


// =========================================================
// Check MBZ upload
// =========================================================

if (!isset($_FILES['mbz'])) {

    http_response_code(400);

    coursemigrator_response(
        false,
        'MBZ file is missing.'
    );
}


$mbz = $_FILES['mbz'];

if ($mbz['error'] !== UPLOAD_ERR_OK) {

    http_response_code(400);

    coursemigrator_response(
        false,
        'MBZ upload failed.',
        [
            'upload_error' => $mbz['error']
        ]
    );
}


// =========================================================
// Read metadata
// =========================================================

if (empty($_POST['metadata'])) {

    http_response_code(400);

    coursemigrator_response(
        false,
        'Metadata JSON is missing.'
    );
}


$metadata = json_decode(
    $_POST['metadata'],
    true
);


if (!is_array($metadata)) {

    http_response_code(400);

    coursemigrator_response(
        false,
        'Invalid metadata JSON.'
    );
}


// =========================================================
// Validate hierarchy
// =========================================================

$hierarchy = $metadata['hierarchy'] ?? [];

$institute = trim($hierarchy['institute'] ?? '');
$program = trim($hierarchy['program'] ?? '');
$batch = trim($hierarchy['batch'] ?? '');
$semester = trim($hierarchy['semester'] ?? '');
$specialization = trim($hierarchy['specialization'] ?? '');


if ($institute === '') {

    coursemigrator_response(
        false,
        'Institute name is missing from metadata.'
    );
}

if ($program === '') {

    coursemigrator_response(
        false,
        'Program name is missing from metadata.'
    );
}

//commingting because i want the course to be visible from the other categories too
// if ($batch === '') {

//     coursemigrator_response(
//         false,
//         'Batch name is missing from metadata.'
//     );
// }

// if ($semester === '') {

//     coursemigrator_response(
//         false,
//         'Semester name is missing from metadata.'
//     );
// }


// =========================================================
// Validate course information
// =========================================================

$coursefullname = trim(
    $metadata['course']['fullname'] ?? ''
);

$courseshortname = trim(
    $metadata['course']['shortname'] ?? ''
);


if ($coursefullname === '') {

    coursemigrator_response(
        false,
        'Course fullname is missing from metadata.'
    );
}

if ($courseshortname === '') {

    coursemigrator_response(
        false,
        'Course shortname is missing from metadata.'
    );
}


// =========================================================
// Create receiver migration record
// =========================================================

$source_moodle_url = trim($metadata['source']['moodle_url'] ?? '');
$source_course_id = (int)($metadata['source']['course_id'] ?? 0);

// Extract the source metadata counts sent by the origin Moodle.
$sourcemetadata = $metadata['metadata_counts'] ?? null;

if (!is_array($sourcemetadata)) {
    http_response_code(400);
    coursemigrator_response(false,'Source metadata counts are missing or invalid.');
}

$sourcemetadatajson = null;

if (is_array($sourcemetadata)) {
    $sourcemetadatajson = json_encode(
        $sourcemetadata,
        JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($sourcemetadatajson === false) {
        throw new coding_exception('Failed to encode source metadata JSON.');
    }
}

$migrationid = $DB->insert_record('course_migrator_receiver', [
    'source_moodle_url' => $source_moodle_url,
    'source_course_id' => $source_course_id,
    'source_course_fullname' => $coursefullname,
    'source_course_shortname' => $courseshortname,
    'institute' => $institute,
    'program' => $program,
    'batch' => $batch,
    'semester' => $semester,
    'specialization' => $specialization,
    'source_metadata_json' => $sourcemetadatajson, 
    'restore_status' => 'pending',
    'created_at' => time(),
    'updated_at' => time(),
]);





// =========================================================
// Create external Course Migrator directory
// Outside moodledata and public_html.
// =========================================================

// $baseincomingdir = dirname($CFG->dirroot) . DIRECTORY_SEPARATOR . 'SIT_P';

// =========================================================
// Create external Course Migrator directory structure
// =========================================================

$baseDir = '/data/coursemigrator';

// $baseDir = '/var/www/coursemigrator';

// error_log('[Course Migrator] Base directory: ' . $baseDir);
// error_log('[Course Migrator] Base exists: ' . (is_dir($baseDir) ? 'YES' : 'NO'));
// error_log('[Course Migrator] Base writable: ' . (is_writable($baseDir) ? 'YES' : 'NO'));
// error_log('[Course Migrator] Moodle dirroot: ' . $CFG->dirroot);

$incomingdir = $baseDir . DIRECTORY_SEPARATOR .
    coursemigrator_folder_name($institute);

// Program
$incomingdir .= DIRECTORY_SEPARATOR .
    coursemigrator_folder_name($program);

// Batch
if ($batch !== '') {
    $incomingdir .= DIRECTORY_SEPARATOR .
        coursemigrator_folder_name($batch);
}

// Semester
if ($semester !== '') {
    $incomingdir .= DIRECTORY_SEPARATOR .
        coursemigrator_folder_name($semester);
}

// Specialization
if ($specialization !== '') {
    $incomingdir .= DIRECTORY_SEPARATOR .
        coursemigrator_folder_name($specialization);
}

// Create the complete directory structure.
if (!is_dir($incomingdir)) {
    if (!mkdir($incomingdir, 0755, true) && !is_dir($incomingdir)) {
        error_log(
            'Course Migrator: Failed to create directory: ' .
            $incomingdir
        );

        http_response_code(500);

        coursemigrator_response(
            false,
            'Failed to create Course Migrator directory structure.',
            [
                'directory' => $incomingdir,
                'reason' => 'Directory creation failed. Check parent directory permissions.'
            ]
        );
    }
}

// Verify that the complete destination is writable.
if (!is_writable($incomingdir)) {
    error_log(
        'Course Migrator: Directory is not writable: ' .
        $incomingdir
    );

    http_response_code(500);

    coursemigrator_response(
        false,
        'Course Migrator directory is not writable.',
        [
            'directory' => $incomingdir
        ]
    );
}






// ---------------------------------------------------------
// Create directory structure
// ---------------------------------------------------------

// if (!check_dir_exists($incomingdir, true, true)) {

//    http_response_code(500);

//    coursemigrator_response(false,'Failed to create Course Migrator directory structure.');
//}


// =========================================================
// Save MBZ
// =========================================================

$filename = clean_filename(
    basename($mbz['name'])
);


if (
    empty($filename) ||
    strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'mbz'
) {

    coursemigrator_response(
        false,
        'Invalid MBZ filename.'
    );
}


$filepath = $incomingdir .
    DIRECTORY_SEPARATOR .
    $filename;


if (!move_uploaded_file(
    $mbz['tmp_name'],
    $filepath
)) {

    http_response_code(500);

    coursemigrator_response(
        false,
        'Failed to save MBZ file.'
    );
}


$DB->update_record('course_migrator_receiver', [
    'id' => $migrationid,
    'mbz_filename' => $filename,
    'updated_at' => time(),
]);


// =========================================================
// Save metadata JSON
// =========================================================

$metadatafile = $filepath . '.json';

$metadatajson = json_encode(
    $metadata,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
);


if (file_put_contents(
    $metadatafile,
    $metadatajson
) === false) {

    @unlink($filepath);

    http_response_code(500);

    coursemigrator_response(
        false,
        'Failed to save metadata JSON.'
    );
}


// =========================================================
// Find/Create category helper
// =========================================================

function coursemigrator_get_or_create_category(
    $name,
    $parentid
) {

    global $DB;

    $category = $DB->get_record(
        'course_categories',
        [
            'name' => $name,
            'parent' => $parentid
        ],
        '*',
        IGNORE_MULTIPLE
    );

    if ($category) {
        return $category;
    }


    $categorydata = new stdClass();

    $categorydata->name = $name;
    $categorydata->parent = $parentid;
    $categorydata->visible = 1;


    $category = core_course_category::create(
        $categorydata
    );


    return $DB->get_record(
        'course_categories',
        ['id' => $category->id],
        '*',
        MUST_EXIST
    );
}


$logs = [];

// =========================================================
// Create / Find hierarchy
// =========================================================

try {

    // -----------------------------------------------------
    // Institute
    // -----------------------------------------------------

    $institute_category =
        coursemigrator_get_or_create_category(
            $institute,
            0
        );
        
    $logs[] = 'Institute ready: ' . $institute . ' (Category ID: ' . $institute_category->id . ')';


    // -----------------------------------------------------
    // Program
    // -----------------------------------------------------

    $program_category =
        coursemigrator_get_or_create_category(
            $program,
            $institute_category->id
        );
        
    $logs[] = 'Program ready: ' . $program . ' (Category ID: ' . $program_category->id . ')';
    
    $target_category = $program_category;

    // -----------------------------------------------------
    // Batch
    // -----------------------------------------------------

    if ($batch !== '') {
    
        $batch_category =
            coursemigrator_get_or_create_category(
                $batch,
                $program_category->id
            );
    
        $target_category = $batch_category;
    
        $logs[] = 'Batch ready: ' . $batch .
            ' (Category ID: ' . $batch_category->id . ')';
    
    } else {
    
        $logs[] = 'No batch provided. Course will be restored into Program category.';
    }


    // -----------------------------------------------------
    // Semester
    // -----------------------------------------------------

    if ($semester !== '') {
    
        $semester_category =
            coursemigrator_get_or_create_category(
                $semester,
                $target_category->id
            );
    
        $target_category = $semester_category;
    
        $logs[] = 'Semester ready: ' . $semester .
            ' (Category ID: ' . $semester_category->id . ')';
    
    } else {
    
        $logs[] = 'No semester provided. Course will be restored into the current target category.';
    }


    // -----------------------------------------------------
    // Specialization
    // -----------------------------------------------------


    if ($specialization !== '') {
    
        $specialization_category =
            coursemigrator_get_or_create_category(
                $specialization,
                $target_category->id
            );
    
        $target_category = $specialization_category;
    
        $logs[] = 'Specialization ready: ' . $specialization .
            ' (Category ID: ' . $specialization_category->id . ')';
    
    } else {
    
        $logs[] = 'No specialization provided. Course will be restored into the current target category.';
    }


    // =====================================================
    // Extract MBZ
    // =====================================================

    $backupid = restore_controller::get_tempdir_name(
        SITEID,
        $USER->id
    );

    $backuppath = make_backup_temp_directory(
        $backupid
    );
    
    $logs[] = 'MBZ extraction directory created.';


    $packer = get_file_packer(
        'application/vnd.moodle.backup'
    );


    if (!$packer->extract_to_pathname(
        $filepath,
        $backuppath
    )) {

        throw new moodle_exception(
            'Failed to extract MBZ backup.'
        );
    }
    
    $logs[] = 'MBZ extracted successfully.';
    
    
    // =====================================================
    // Check for duplicate course shortname
    // =====================================================
    
    $existingcourse = $DB->get_record(
        'course',
        ['shortname' => $courseshortname],
        'id,fullname,shortname'
    );
    
    if ($existingcourse) {
    
        $logs[] = 'Duplicate course detected.';
        $logs[] = 'Course shortname already exists: ' . $courseshortname;
        $logs[] = 'Existing destination course ID: ' . $existingcourse->id;
        $logs[] = 'Existing destination course fullname: ' . $existingcourse->fullname;
    
        throw new moodle_exception(
            'A course with the shortname "' . $courseshortname .
            '" already exists on the destination. ' .
            'Existing course ID: ' . $existingcourse->id .
            '. Course restore was stopped to prevent duplicate course creation.'
        );
    }


    // =====================================================
    // Create new destination course
    // =====================================================

    $newcourseid = restore_dbops::create_new_course(
        $coursefullname,
        $courseshortname,
        $target_category->id
    );
    
    $logs[] = 'Destination course created. Course ID: ' . $newcourseid;


    // =====================================================
    // Restore MBZ
    // =====================================================

    $restorecontroller = new restore_controller(
        $backupid,
        $newcourseid,
        backup::INTERACTIVE_NO,
        backup::MODE_GENERAL,
        get_admin()->id,
        backup::TARGET_NEW_COURSE
    );
    
    // Configure restore settings.
    $restoreplan = $restorecontroller->get_plan();
    
    if ($restoreplan->setting_exists('users')) {
        $restoreplan->get_setting('users')->set_value(true);
    }
    
    if ($restoreplan->setting_exists('role_assignments')) {
        $restoreplan->get_setting('role_assignments')->set_value(true);
    }
    
    if ($restoreplan->setting_exists('enrolments')) {
        $restoreplan->get_setting('enrolments')->set_value(
            backup::ENROL_NEVER
        );
    }
    
    $logs[] = 'Restore users setting before precheck: ' . ($restoreplan->get_setting('users')->get_value() ? 'YES' : 'NO');

    $logs[] = 'Restore role assignments setting before precheck: ' . ($restoreplan->get_setting('role_assignments')->get_value() ? 'YES' : 'NO');
    
    $logs[] = 'Restore enrolments setting before precheck: ' . $restoreplan->get_setting('enrolments')->get_value();


    // -----------------------------------------------------
    // Precheck
    // -----------------------------------------------------

    // if (!$restorecontroller->execute_precheck()) {

    //     $precheck = $restorecontroller->get_precheck_results();

    //     $restorecontroller->destroy();

    //     throw new moodle_exception(
    //         'Restore precheck failed: ' .
    //         json_encode($precheck)
    //     );
    // }
    
    try {

        if (!$restorecontroller->execute_precheck()) {
    
            $precheck = $restorecontroller->get_precheck_results();
    
            $logs[] = 'Restore precheck returned false.';
            $logs[] = 'Precheck results: ' . json_encode($precheck);
    
            throw new moodle_exception(
                'Restore precheck failed: ' .
                json_encode($precheck)
            );
        }
    
    } catch (Throwable $e) {
    
        $logs[] = 'Restore precheck exception: ' . $e->getMessage();
    
        throw $e;
    }
    
    
    
    $logs[] = 'Restore users setting after precheck: ' . ($restoreplan->get_setting('users')->get_value() ? 'YES' : 'NO');

    $logs[] = 'Restore role assignments setting after precheck: ' . ($restoreplan->get_setting('role_assignments')->get_value() ? 'YES' : 'NO');
    
    $logs[] = 'Restore enrolments setting after precheck: ' . $restoreplan->get_setting('enrolments')->get_value();


    // -----------------------------------------------------
    // Execute restore
    // -----------------------------------------------------
    
    
    $logs[] = 'Starting Moodle course restore.';
    $logs[] = 'Restore target category ID: ' . $target_category->id;
    $logs[] = 'Restore mode: MODE_GENERAL.';
    $logs[] = 'Restore users and enrolments according to configured restore settings.';
    
    $restorecontroller->execute_plan();


    // Check actual users enrolled after restore.
    $context = context_course::instance($newcourseid);
    
    $enrolledusers = get_enrolled_users($context);
    
    $logs[] = 'Users enrolled after restore: ' . count($enrolledusers);
    
    if (count($enrolledusers) > 0) {
        $logs[] = 'User enrolment restore appears successful.';
    } else {
        $logs[] = 'WARNING: No users are enrolled in the destination course.';
    }
    
    // =====================================================
    // Verify destination course
    // =====================================================
    
    $destination_course = $DB->get_record(
        'course',
        [
            'id' => $newcourseid
        ],
        'id,fullname,shortname',
        IGNORE_MISSING
    );
    
    if (!$destination_course) {
        throw new moodle_exception(
            'Destination course verification failed: restored course does not exist.'
        );
    }
    
    $logs[] = 'Destination course verification successful.';
    $logs[] = 'Destination course ID: ' . $destination_course->id;
    
    
    // =====================================================
    // Collect destination metadata and save both JSON values
    // =====================================================
    
    $destinationmetadata = coursemigrator_get_course_metadata(
        (int)$destination_course->id
    );
    
    $destinationmetadatajson = json_encode(
        $destinationmetadata,
        JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    
    if ($destinationmetadatajson === false) {
        throw new coding_exception('Failed to encode destination metadata JSON.');
    }
    
    // Save both source and destination metadata JSON.
    $DB->update_record('course_migrator_receiver', [
        'id' => $migrationid,
        'source_metadata_json' => $sourcemetadatajson,
        'destination_metadata_json' => $destinationmetadatajson,
        'updated_at' => time(),
    ]);
    
    $logs[] = 'Source metadata JSON saved.';
    $logs[] = 'Destination metadata JSON collected and saved.';
    
    
    {
        
        // =====================================================
        // Compare source and destination metadata
        // =====================================================
    
        // Read both JSON values back from the database.
        $savedmetadata = $DB->get_record(
            'course_migrator_receiver',
            ['id' => $migrationid],
            'source_metadata_json, destination_metadata_json',
            MUST_EXIST
        );
    
        $sourcecounts = json_decode(
            $savedmetadata->source_metadata_json,
            true
        );
    
        $destinationcounts = json_decode(
            $savedmetadata->destination_metadata_json,
            true
        );
    
        if (
            !is_array($sourcecounts) ||
            !is_array($destinationcounts) ||
            json_last_error() !== JSON_ERROR_NONE
        ) {
            throw new moodle_exception(
                'Metadata verification failed: source or destination JSON is invalid.'
            );
        }
    
        // The seven metrics collected by both Moodle sites.
//collecting all seven but the students private files is excluded from comparision, because we can not get it.
// Moodle course backups do not normally include users' personal private files. These files belong to the users, not to a particular course.

        $metadatakeys = [
            'enrolled_users',
            'course_files',
           // 'students_private_files',
            'activities',
            'attendance_activities',
            'attendance_sessions',
            'feedback_activities',
        ];
    
        $metadatacomparison = [];
        $metadataverification = true;
    
        foreach ($metadatakeys as $key) {
            // Missing or non-numeric counts must not be treated as zero.
            if (
                !array_key_exists($key, $sourcecounts) ||
                !array_key_exists($key, $destinationcounts) ||
                !is_numeric($sourcecounts[$key]) ||
                !is_numeric($destinationcounts[$key])
            ) {
                $metadataverification = false;
    
                $metadatacomparison[$key] = [
                    'source' => $sourcecounts[$key] ?? null,
                    'destination' => $destinationcounts[$key] ?? null,
                    'match' => false,
                    'message' => 'Missing or invalid metadata count.',
                ];
    
                $logs[] = "Metadata mismatch: {$key} is missing or invalid.";
    
                continue;
            }
    
            $sourcevalue = (int)$sourcecounts[$key];
            $destinationvalue = (int)$destinationcounts[$key];
            $matches = ($sourcevalue === $destinationvalue);
    
            $metadatacomparison[$key] = [
                'source' => $sourcevalue,
                'destination' => $destinationvalue,
                'match' => $matches,
                'message' => $matches
                    ? 'Count matches.'
                    : 'Count mismatch.',
            ];
    
            if (!$matches) {
                $metadataverification = false;
    
                $logs[] = "Metadata mismatch: {$key}. Source: {$sourcevalue}, Destination: {$destinationvalue}.";
            } else {
                $logs[] = "Metadata matched: {$key} = {$sourcevalue}.";
            }
        }
    
        $logs[] = $metadataverification
            ? 'Metadata verification successful: all seven counts match.'
            : 'Metadata verification failed: one or more counts do not match.';

    }
    

    $restorecontroller->destroy();

    // =====================================================
    // Success
    // =====================================================
    
    $DB->update_record('course_migrator_receiver', [
        'id' => $migrationid,
    
        'destination_course_id' => $newcourseid,
        'destination_course_fullname' => $coursefullname,
        'destination_course_shortname' => $courseshortname,
    
        'mbz_filename' => $filename,
    
        'restore_status' => 'success',
        'restore_message' => 'Course restored successfully.',
        'restore_logs' => json_encode($logs, JSON_UNESCAPED_SLASHES),
    
        'updated_at' => time(),
    ]);
    
    

    coursemigrator_response(
        true,
        $metadataverification
            ? 'Course restored successfully and all metadata counts match.'
            : 'Course restored, but metadata verification failed. Origin cleanup must not proceed.',
        [
            'logs' => $logs,
            
            'courseid' => $newcourseid,

            'course' => [
                'fullname' => $coursefullname,
                'shortname' => $courseshortname
            ],
            
            'verification' => [
                'success' => $metadataverification,
                'restore_completed' => true,
                'course_exists' => true,
                'courseid' => $destination_course->id,
                'metadata_verification' => [
                    'success' => $metadataverification,
                    'message' => $metadataverification
                        ? 'All seven metadata counts match.'
                        : 'One or more metadata counts differ or are invalid.',
                    'comparison' => $metadatacomparison,
                ],
            ],

            'hierarchy' => [
                'institute' => $institute,
                'program' => $program,
                'batch' => $batch,
                'semester' => $semester,
                'specialization' => $specialization
            ],

            'categoryid' => $target_category->id,

            'mbz' => [
                'filename' => $filename,
                'size' => filesize($filepath)
            ],
            
        ]
    );


} catch (Throwable $e) {

    if (!empty($migrationid)) {

        $DB->update_record('course_migrator_receiver', [
            'id' => $migrationid,
            'restore_status' => 'failed',
            'restore_message' => $e->getMessage(),
            'restore_logs' => json_encode($logs, JSON_UNESCAPED_SLASHES),
            'updated_at' => time(),
        ]);
    }

    http_response_code(500);

    coursemigrator_response(
        false,
        $e->getMessage(),
        [
            'logs' => $logs,
            'error' => $e->getMessage()
        ]
    );
}