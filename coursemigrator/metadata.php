<?php
// metadata.php
// Destination-side metadata collection for Course Migrator.
// Include this file from receive.php after a course has been restored.

defined('MOODLE_INTERNAL') || die();

/**
 * Collect metadata counts for a restored destination course.
 *
 * The returned keys must remain consistent with the origin metadata.
 *
 * @param int $courseid Destination Moodle course ID.
 * @return array<string, int> Metadata counts.
 * @throws dml_exception If a required database query fails.
 */
function coursemigrator_get_course_metadata(int $courseid): array {
    global $DB;

    // Confirm that the destination course exists.
    $course = $DB->get_record(
        'course',
        ['id' => $courseid],
        'id',
        IGNORE_MISSING
    );

    if (!$course || $courseid <= SITEID) {
        throw new invalid_parameter_exception(
            'Invalid destination course ID for metadata collection.'
        );
    }

    $context = context_course::instance($courseid);

    // 1. Active enrolled users, excluding deleted and guest users.
    $guestid = (int)$DB->get_field(
        'user',
        'id',
        ['username' => 'guest']
    );

    $enrolledusers = (int)$DB->count_records_sql(
        "SELECT COUNT(DISTINCT ue.userid)
           FROM {user_enrolments} ue
           JOIN {enrol} e ON e.id = ue.enrolid
           JOIN {user} u ON u.id = ue.userid
          WHERE e.courseid = :courseid
            AND e.status = 0
            AND ue.status = 0
            AND u.deleted = 0
            AND u.id <> :guestid",
        [
            'courseid' => $courseid,
            'guestid' => $guestid,
        ]
    );

    // 2. File records in the course context and all descendant contexts.
    // Exclude directory placeholder records (filename = '.').

$coursefiles = (int)$DB->count_records_sql(
    "SELECT COUNT(f.id)
       FROM {files} f
       JOIN {context} ctx ON ctx.id = f.contextid
      WHERE (
                ctx.id = :contextid
                OR ctx.path LIKE :contextpath
            )
        AND f.filename <> :directory
        AND NOT (
            f.component = :editpdfcomponent
            AND f.filearea IN (:combined, :pages)
        )
        AND NOT (
            f.component = :backupcomponent
            AND f.filearea = :backuparea
        )",
    [
        'contextid' => $context->id,
        'contextpath' => $context->path . '/%',
        'directory' => '.',
        'editpdfcomponent' => 'assignfeedback_editpdf',
        'combined' => 'combined',
        'pages' => 'pages',
        'backupcomponent' => 'backup',
        'backuparea' => 'course',
    ]
);   
 

    // 3. Private files belonging to users actively enrolled in this course.
    $privatefiles = (int)$DB->count_records_sql(
        "SELECT COUNT(f.id)
           FROM {files} f
           JOIN {context} uc ON uc.id = f.contextid
          WHERE uc.contextlevel = :usercontext
            AND f.component = :component
            AND f.filearea = :filearea
            AND f.filename <> :directory
            AND EXISTS (
                SELECT 1
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE ue.userid = uc.instanceid
                   AND e.courseid = :courseid
                   AND e.status = 0
                   AND ue.status = 0
            )",
        [
            'usercontext' => CONTEXT_USER,
            'component' => 'user',
            'filearea' => 'private',
            'directory' => '.',
            'courseid' => $courseid,
        ]
    );

    // 4. Activities excluding modules being deleted.
    $activities = (int)$DB->count_records_sql(
        "SELECT COUNT(id)
           FROM {course_modules}
          WHERE course = :courseid
            AND module <> 0
            AND deletioninprogress = 0",
        ['courseid' => $courseid]
    );

    // 5. Attendance activities and sessions.
    $attendanceactivities = 0;
    $attendancesessions = 0;

    if ($DB->get_manager()->table_exists(
        new xmldb_table('attendance')
    )) {
        $attendanceactivities = (int)$DB->count_records(
            'attendance',
            ['course' => $courseid]
        );

        $attendancesessions = (int)$DB->count_records_sql(
            "SELECT COUNT(ats.id)
               FROM {attendance_sessions} ats
               JOIN {attendance} a ON a.id = ats.attendanceid
              WHERE a.course = :courseid",
            ['courseid' => $courseid]
        );
    }

    // 6. Feedback activities only. Responses are intentionally excluded.
    $feedbackactivities = 0;

    if ($DB->get_manager()->table_exists(
        new xmldb_table('feedback')
    )) {
        $feedbackactivities = (int)$DB->count_records(
            'feedback',
            ['course' => $courseid]
        );
    }

    return [
        'enrolled_users' => max(0, $enrolledusers),
        'course_files' => max(0, $coursefiles),
        'students_private_files' => max(0, $privatefiles),
        'activities' => max(0, $activities),
        'attendance_activities' => max(0, $attendanceactivities),
        'attendance_sessions' => max(0, $attendancesessions),
        'feedback_activities' => max(0, $feedbackactivities),
    ];
}