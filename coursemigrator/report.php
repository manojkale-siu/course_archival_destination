<?php

require_once(__DIR__ . '/../../config.php');

require_login();

global $DB, $OUTPUT, $PAGE;

$context = context_system::instance();

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/coursemigrator/report.php'));
$PAGE->set_title('Course Archival & Cleanup Manager');
$PAGE->set_heading('Course Archival & Cleanup Report');

/* ---------------------------------------------------------
 * Check whether the migration table exists
 * --------------------------------------------------------- */

$tablemanager = $DB->get_manager();

if (!$tablemanager->table_exists(
    new xmldb_table('course_migrator_receiver')
)) {
    echo $OUTPUT->header();

    echo html_writer::div(
        html_writer::tag(
            'h4',
            'Migration Report Unavailable'
        ) .
        html_writer::tag(
            'p',
            'The Course Migrator database table was not found. ' .
            'Please verify that the migration component is configured correctly.'
        ),
        'alert alert-warning'
    );

    echo $OUTPUT->footer();
    exit;
}


// =========================================================
// Filters
// =========================================================

$search = optional_param('search', '', PARAM_RAW_TRIMMED);

$institute = optional_param('institute', '', PARAM_RAW_TRIMMED);
$program = optional_param('program', '', PARAM_RAW_TRIMMED);
$batch = optional_param('batch', '', PARAM_RAW_TRIMMED);
$semester = optional_param('semester', '', PARAM_RAW_TRIMMED);
$specialization = optional_param('specialization', '', PARAM_RAW_TRIMMED);


// =========================================================
// Pagination
// =========================================================

$perpage = 10;

$page = optional_param('page', 0, PARAM_INT);


// =========================================================
// Build WHERE conditions
// =========================================================

$where = [];
$params = [];


// Search course fullname.

if ($search !== '') {

    $where[] = $DB->sql_like(
        'r.source_course_fullname',
        ':search',
        false,
        false
    );

    $params['search'] = '%' . $DB->sql_like_escape($search) . '%';
}


// Institute.

if ($institute !== '') {

    $where[] = 'r.institute = :institute';

    $params['institute'] = $institute;
}


// Program.

if ($program !== '') {

    $where[] = 'r.program = :program';

    $params['program'] = $program;
}


// Batch.

if ($batch !== '') {

    $where[] = 'r.batch = :batch';

    $params['batch'] = $batch;
}


// Semester.

if ($semester !== '') {

    $where[] = 'r.semester = :semester';

    $params['semester'] = $semester;
}


// Specialization.

if ($specialization !== '') {

    $where[] = 'r.specialization = :specialization';

    $params['specialization'] = $specialization;
}


$where_sql = '';

if (!empty($where)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where);
}

/* ---------------------------------------------------------
 * Export destination records as Excel-compatible CSV
 * --------------------------------------------------------- */

$download = optional_param('download', 0, PARAM_BOOL);

if ($download) {
    $batchsize = 500;
    $offset = 0;

    \core\session\manager::write_close();
    set_time_limit(0);

    $filename = 'course_archival_destination_report_' .
        date('Y-m-d_H-i-s') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="' .
        $filename . '"'
    );
    header('Cache-Control: no-store, no-cache, must-revalidate');

    // UTF-8 BOM for Microsoft Excel.
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');

    // Columns belonging to course_migrator_receiver.
    $columns = [
        'id',
        'source_moodle_url',
        'source_course_id',
        'source_course_fullname',
        'source_course_shortname',
        'destination_course_id',
        'destination_course_fullname',
        'destination_course_shortname',
        'institute',
        'program',
        'batch',
        'semester',
        'specialization',
        'mbz_filename',
        'restore_status',
        'restore_message',
        'restore_logs',
        'created_at',
        'updated_at'
    ];

    fputcsv($output, $columns);

    // Export records in batches of 500.
    do {
        $sql = "SELECT r.*
                  FROM {course_migrator_receiver} r
                  $where_sql
              ORDER BY r.id DESC";

        $recordset = $DB->get_recordset_sql(
            $sql,
            $params,
            $offset,
            $batchsize
        );

        $batchcount = 0;

        foreach ($recordset as $record) {
            $row = [];

            foreach ($columns as $column) {
                $value = $record->$column ?? '';

                if (
                    in_array(
                        $column,
                        ['created_at', 'updated_at'],
                        true
                    ) &&
                    $value !== ''
                ) {
                    $value = userdate(
                        (int)$value,
                        '%d %b %Y %H:%M'
                    );
                }

                $row[] = $value;
            }

            fputcsv($output, $row);
            $batchcount++;
        }

        $recordset->close();

        $offset += $batchcount;

        if (function_exists('ob_flush')) {
            @ob_flush();
        }

        flush();

    } while ($batchcount === $batchsize);

    fclose($output);
    exit;
}


// =========================================================
// Get filter options
// =========================================================

$institutes = $DB->get_records_sql(
    "SELECT DISTINCT institute
       FROM {course_migrator_receiver}
      WHERE institute IS NOT NULL
        AND institute <> ''
   ORDER BY institute ASC"
);

$programs = $DB->get_records_sql(
    "SELECT DISTINCT program
       FROM {course_migrator_receiver}
      WHERE program IS NOT NULL
        AND program <> ''
   ORDER BY program ASC"
);

$batches = $DB->get_records_sql(
    "SELECT DISTINCT batch
       FROM {course_migrator_receiver}
      WHERE batch IS NOT NULL
        AND batch <> ''
   ORDER BY batch ASC"
);

$semesters = $DB->get_records_sql(
    "SELECT DISTINCT semester
       FROM {course_migrator_receiver}
      WHERE semester IS NOT NULL
        AND semester <> ''
   ORDER BY semester ASC"
);

$specializations = $DB->get_records_sql(
    "SELECT DISTINCT specialization
       FROM {course_migrator_receiver}
      WHERE specialization IS NOT NULL
        AND specialization <> ''
   ORDER BY specialization ASC"
);


// =========================================================
// Count records
// =========================================================

$totalrecords = $DB->count_records_sql(
    "SELECT COUNT(*)
       FROM {course_migrator_receiver} r
       $where_sql",
    $params
);


// =========================================================
// Fetch records
// =========================================================

$records = $DB->get_records_sql(
    "SELECT
        r.*,

        c.id AS existing_course_id

     FROM {course_migrator_receiver} r

     LEFT JOIN {course} c
       ON c.id = r.destination_course_id

     $where_sql

     ORDER BY r.id DESC",
    $params,
    $page * $perpage,
    $perpage
);


// =========================================================
// Status badge helper
// =========================================================

function receiver_status_badge(?string $status): string
{
    $status = strtolower(trim((string)$status));

    switch ($status) {

        case 'success':
            $class = 'badge bg-success';
            break;

        case 'failed':
            $class = 'badge bg-danger';
            break;

        case 'pending':
            $class = 'badge bg-warning text-dark';
            break;

        default:
            $class = 'badge bg-secondary';
            $status = $status ?: '-';
            break;
    }

    return html_writer::tag(
        'span',
        ucfirst($status),
        ['class' => $class]
    );
}


// =========================================================
// Build filter URL
// =========================================================

$baseurl = new moodle_url('/local/coursemigrator/report.php');

$PAGE->requires->css( new moodle_url('https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css') );

echo $OUTPUT->header();

?>

<div class="container-fluid">

    <!-- =====================================================
         Header
         ===================================================== -->

    <div class="d-flex justify-content-between align-items-center mb-3">

        <!--<h3>Course Migrator Report</h3>-->

    </div>
    
    <!-- Alert Notification -->
    <div id="restore-alert" class="mb-3" role="status" aria-live="polite"></div>


    <!-- =====================================================
         Search + Filters
         ===================================================== -->

    <form method="get" action="<?php echo $baseurl; ?>" class="mb-4">

        <div class="card">
            
            <div class="card-body">
            
                <div class="row g-3">


                    <!-- Search -->

                    <div class="col-md-4">

                        <label for="search"
                               class="form-label">

                            Course Full Name

                        </label>

                        <input
                            type="text"
                            id="search"
                            name="search"
                            value="<?php echo s($search); ?>"
                            class="form-control"
                            placeholder="Search course fullname..."
                        >

                    </div>


                    <!-- Institute -->

                    <div class="col-md-4">

                        <label for="institute"
                               class="form-label">

                            Institute

                        </label>

                        <select
                            id="institute"
                            name="institute"
                            class="form-select">

                            <option value="">
                                All Institutes
                            </option>

                            <?php foreach ($institutes as $item): ?>

                                <option
                                    value="<?php echo s($item->institute); ?>"
                                    <?php
                                    echo ($institute === $item->institute)
                                        ? 'selected'
                                        : '';
                                    ?>>

                                    <?php echo s($item->institute); ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- Program -->

                    <div class="col-md-4">

                        <label for="program"
                               class="form-label">

                            Program

                        </label>

                        <select
                            id="program"
                            name="program"
                            class="form-select">

                            <option value="">
                                All Programs
                            </option>

                            <?php foreach ($programs as $item): ?>

                                <option
                                    value="<?php echo s($item->program); ?>"
                                    <?php
                                    echo ($program === $item->program)
                                        ? 'selected'
                                        : '';
                                    ?>>

                                    <?php echo s($item->program); ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- Batch -->

                    <div class="col-md-4">

                        <label for="batch"
                               class="form-label">

                            Batch

                        </label>

                        <select
                            id="batch"
                            name="batch"
                            class="form-select">

                            <option value="">
                                All Batches
                            </option>

                            <?php foreach ($batches as $item): ?>

                                <option
                                    value="<?php echo s($item->batch); ?>"
                                    <?php
                                    echo ($batch === $item->batch)
                                        ? 'selected'
                                        : '';
                                    ?>>

                                    <?php echo s($item->batch); ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- Semester -->

                    <div class="col-md-4">

                        <label for="semester"
                               class="form-label">

                            Semester

                        </label>

                        <select
                            id="semester"
                            name="semester"
                            class="form-select">

                            <option value="">
                                All Semesters
                            </option>

                            <?php foreach ($semesters as $item): ?>

                                <option
                                    value="<?php echo s($item->semester); ?>"
                                    <?php
                                    echo ($semester === $item->semester)
                                        ? 'selected'
                                        : '';
                                    ?>>

                                    <?php echo s($item->semester); ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- Specialization -->

                    <div class="col-md-4">

                        <label for="specialization"
                               class="form-label">

                            Specialization

                        </label>

                        <select
                            id="specialization"
                            name="specialization"
                            class="form-select">

                            <option value="">
                                All Specializations
                            </option>

                            <?php foreach ($specializations as $item): ?>

                                <option
                                    value="<?php echo s($item->specialization); ?>"
                                    <?php
                                    echo ($specialization === $item->specialization)
                                        ? 'selected'
                                        : '';
                                    ?>>

                                    <?php echo s($item->specialization); ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                </div>
            
                <!-- Buttons -->
                <div class="d-flex justify-content-end gap-2 mt-3">
                    
                    <button type="submit" class="btn btn-primary"> Search / Filter </button>
                    
                    <a href="<?php echo $baseurl; ?>" class="btn btn-secondary"> Reset </a>
                    
                    <button
                        type="submit"
                        name="download"
                        value="1"
                        class="btn btn-success">
                        Download Excel (CSV)
                    </button>
                    
                </div>
            
            </div>
            
        </div>
        
    </form>


    <!-- =====================================================
         Report
         ===================================================== -->

    <?php if (empty($records)): ?>

        <div class="alert alert-info">

            No course migration records found.

        </div>

    <?php else: ?>
    
        <p>Total Records : <?php echo "$totalrecords"; ?></p>

        <div class="table-responsive">

            <table class="table table-bordered table-striped table-hover align-middle">

                <thead class="table-light">

                    <tr>

                        <th>SR. NO.</th>

                        <th>Course</th>

                        <th>Category Hierarchy</th>

                        <th>MBZ</th>

                        <th>Restore Status</th>

                        <th>Created</th>

                        <th>Action</th>

                    </tr>

                </thead>


                <tbody>

                <?php 
                    
                    $srno = ($page * $perpage) + 1;
                    
                    foreach ($records as $record): 
                    
                ?>

                    <tr>


                        <!-- ID -->

                        <td>
                            
                            <?php echo $srno++; ?>

                        </td>


                        <!-- Course -->

                        <td>

                            <strong>

                                <?php
                                echo s(
                                    $record->source_course_fullname
                                );
                                ?>

                            </strong>

                            <br>

                            <small class="text-muted">

                                <?php
                                echo s(
                                    $record->source_course_shortname
                                );
                                ?>

                                <br>

                                Source ID:
                                <?php
                                echo (int)$record->source_course_id;
                                ?>

                            </small>

                        </td>


                        <!-- Hierarchy -->

                        <td>

                            <small>

                                <strong>Institute:</strong>
                                <?php
                                echo s($record->institute);
                                ?>

                                <br>

                                <strong>Program:</strong>
                                <?php
                                echo s($record->program);
                                ?>

                                <br>

                                <strong>Batch:</strong>
                                <?php
                                echo s($record->batch);
                                ?>

                                <br>

                                <strong>Semester:</strong>
                                <?php
                                echo s($record->semester);
                                ?>

                                <br>

                                <strong>Specialization:</strong>
                                <?php
                                echo s($record->specialization);
                                ?>

                            </small>

                        </td>


                        <!-- MBZ -->

                        <td>

                            <?php if (!empty($record->mbz_filename)): ?>

                                <span class="badge bg-info text-dark">

                                    MBZ Available

                                </span>

                                <br>

                                <small>

                                    <?php
                                    echo s(
                                        $record->mbz_filename
                                    );
                                    ?>

                                </small>

                            <?php else: ?>

                                <span class="badge bg-danger">

                                    MBZ Missing

                                </span>

                            <?php endif; ?>

                        </td>


                        <!-- Restore Status -->

                        <td>

                            <?php
                            echo receiver_status_badge(
                                $record->restore_status
                            );
                            ?>

                            <?php if (!empty($record->restore_message)): ?>

                                <br>

                                <small class="text-muted">

                                    <?php
                                    echo s(
                                        $record->restore_message
                                    );
                                    ?>

                                </small>

                            <?php endif; ?>

                            <?php if (!empty($record->destination_course_id)): ?>

                                <br>

                                <small class="text-muted">

                                    Destination Course ID:
                                    <?php
                                    echo (int)$record->destination_course_id;
                                    ?>

                                </small>

                            <?php endif; ?>

                        </td>


                        <!-- Created -->

                        <td>

                            <small>

                                <?php

                                echo userdate(
                                    $record->created_at,
                                    '%d %b %Y %H:%M'
                                );

                                ?>

                            </small>

                        </td>


                        <!-- Action -->

                        <td>

                            <?php if (!empty($record->existing_course_id)): ?>

                                <span class="badge bg-success">

                                    Course Exists

                                </span>

                            <?php elseif (
                                !empty($record->mbz_filename)
                            ): ?>

                                
                                <button
                                    type="button"
                                    class="btn btn-primary btn-sm restore-course"
                                    data-id="<?php echo (int)$record->id; ?>"
                                    data-sesskey="<?php echo sesskey(); ?>"
                                    data-url="<?php echo (new moodle_url('/local/coursemigrator/restore.php'))->out(false); ?>">
                                
                                    Restore
                                
                                </button>

                            <?php else: ?>

                                <span class="badge bg-danger">

                                    MBZ Missing

                                </span>

                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>


            <?php

            if ($totalrecords > $perpage) {

                echo $OUTPUT->paging_bar(
                    $totalrecords,
                    $page,
                    $perpage,
                    $baseurl,
                    'page'
                );

            }

            ?>

        </div>

    <?php endif; ?>

</div>

<script>
document.addEventListener('click', async function (event) {
    const button = event.target.closest('.restore-course');

    if (!button) {
        return;
    }

    if (!confirm('Are you sure you want to restore this course?')) {
        return;
    }

    const alertBox = document.getElementById('restore-alert');
    const originalText = button.textContent.trim();

    button.disabled = true;
    button.textContent = 'Restoring...';
    alertBox.replaceChildren();

    function showAlert(message, type, courseUrl = null) {
        const alert = document.createElement('div');
        alert.className = 'alert alert-' + type + ' alert-dismissible fade show';
        alert.setAttribute('role', 'alert');

        const text = document.createElement('span');
        text.textContent = message + ' ';
        alert.appendChild(text);

        if (courseUrl) {
            const link = document.createElement('a');
            link.href = courseUrl;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.textContent = 'Click to view the course';
            alert.appendChild(link);
        }

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-close';
        close.setAttribute('aria-label', 'Close');
        close.onclick = () => alert.remove();
        alert.appendChild(close);

        alertBox.replaceChildren(alert);
        alertBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    try {
        const url = new URL(button.dataset.url, window.location.origin);
        url.searchParams.set('id', button.dataset.id);
        url.searchParams.set('sesskey', button.dataset.sesskey);

        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        });

        const raw = await response.text();
        let data;

        try {
            data = JSON.parse(raw);
        } catch {
            throw new Error(
                'The server did not return JSON. Check the Moodle/PHP error logs.'
            );
        }

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Course restoration failed.');
        }

        showAlert(
            data.message || 'Course restored successfully.',
            data.alreadyexists ? 'info' : 'success',
            data.courseurl || null
        );

        const row = button.closest('tr');
        if (row) {
            const statusCell = row.cells[4];
            statusCell.replaceChildren();

            const badge = document.createElement('span');
            badge.className = 'badge ' +
                (data.alreadyexists ? 'bg-info text-dark' : 'bg-success');
            badge.textContent = data.alreadyexists ? 'Already Exists' : 'Success';
            statusCell.appendChild(badge);

            const message = document.createElement('small');
            message.className = 'text-muted d-block';
            message.textContent = data.message || 'Course restored successfully.';
            statusCell.appendChild(message);

            if (data.courseid) {
                const idText = document.createElement('small');
                idText.className = 'text-muted d-block';
                idText.textContent = 'Destination Course ID: ' + data.courseid;
                statusCell.appendChild(idText);
            }
        }

        const existsBadge = document.createElement('span');
        existsBadge.className = 'badge bg-success';
        existsBadge.textContent = 'Course Exists';
        button.replaceWith(existsBadge);

    } catch (error) {
        showAlert(error.message || 'An unexpected error occurred.', 'danger');
        button.disabled = false;
        button.textContent = originalText;
    }
});
</script>


<?php

echo $OUTPUT->footer();