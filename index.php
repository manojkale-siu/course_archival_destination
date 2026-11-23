<?php

require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/coursemigrator/index.php'));
$PAGE->set_title(get_string('coursemigrator', 'local_coursemigrator'));
$PAGE->set_heading(get_string('coursemigrator', 'local_coursemigrator'));

$programs = $DB->get_records_sql(
    "SELECT id, name
       FROM {course_categories}
      WHERE parent = 0
        AND name NOT IN ('Course Templates', 'VAC-HPM', 'Category 1')
   ORDER BY name ASC"
);

echo $OUTPUT->header();
?>

<h2><?= get_string('coursemigrator', 'local_coursemigrator'); ?></h2>

<div class="mb-3">
    <label for="program">
        <?= get_string('program', 'local_coursemigrator'); ?>
    </label>

    <select id="program" class="form-control">
        <option value="">-- <?= get_string('selectprogram', 'local_coursemigrator'); ?> --</option>

        <?php foreach ($programs as $program): ?>
            <option value="<?= $program->id; ?>">
                <?= s($program->name); ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="mb-3">
    <label for="batch">
        <?= get_string('batch', 'local_coursemigrator'); ?>
    </label>

    <select id="batch" class="form-control" disabled>
        <option value="">-- <?= get_string('selectbatch', 'local_coursemigrator'); ?> --</option>
    </select>
</div>

<div class="mb-3">
    <label for="semester">
        <?= get_string('semester', 'local_coursemigrator'); ?>
    </label>

    <select id="semester" class="form-control" disabled>
        <option value="">-- <?= get_string('selectsemester', 'local_coursemigrator'); ?> --</option>
    </select>
</div>

<div class="mb-3">
    <label for="specialization">
        <?= get_string('specialization', 'local_coursemigrator'); ?>
    </label>

    <select id="specialization" class="form-control" disabled>
        <option value="">-- <?= get_string('selectspecialization', 'local_coursemigrator'); ?> --</option>
    </select>
</div>

<div class="mb-3">
    <label for="courses">
        <?= get_string('courses', 'local_coursemigrator'); ?>
    </label>

    <select id="courses" class="form-control" multiple size="10" disabled>
    </select>
</div>

<div class="mt-3">
    <strong>Selected Courses:</strong>
    <div id="selected-courses">None</div>
</div>


<div class="mt-3">
    <button type="button" id="backup-course" class="btn btn-primary">
        Backup Selected Courses
    </button>
</div>


<?php

// $PAGE->requires->js_call_amd('core/first', 'init');

$PAGE->requires->js_amd_inline(
    <<<JS
require(['jquery'], function($) {

    function resetSelect(selector, placeholder) {
        $(selector)
            .empty()
            .append($('<option>', {
                value: '',
                text: placeholder
            }))
            .prop('disabled', true);
    }

    function loadOptions(url, params, selector, placeholder, callback) {

        $.ajax({
            url: url,
            type: 'GET',
            data: params,
            dataType: 'json',

            success: function(response) {

                $(selector).empty();

                $(selector).append(
                    $('<option>', {
                        value: '',
                        text: placeholder
                    })
                );

                $.each(response, function(index, item) {

                    $(selector).append(
                        $('<option>', {
                            value: item.id,
                            text: item.name
                        })
                    );

                });

                $(selector).prop('disabled', false);

                if (callback) {
                    callback();
                }
            },

            error: function(xhr) {

                console.error('Course Migrator AJAX error:', xhr.responseText);

                $(selector)
                    .empty()
                    .append(
                        $('<option>', {
                            value: '',
                            text: 'Unable to load data'
                        })
                    )
                    .prop('disabled', true);
            }
        });
    }

    $('#program').on('change', function() {

        const programId = $(this).val();

        resetSelect('#batch', '-- Select Batch --');
        resetSelect('#semester', '-- Select Semester --');
        resetSelect('#specialization', '-- Select Specialization --');
        resetSelect('#courses', '');

        if (!programId) {
            return;
        }

        loadOptions(
            'ajax.php',
            {
                action: 'batches',
                parentid: programId
            },
            '#batch',
            '-- Select Batch --'
        );
    });

    $('#batch').on('change', function() {

        const batchId = $(this).val();

        resetSelect('#semester', '-- Select Semester --');
        resetSelect('#specialization', '-- Select Specialization --');
        resetSelect('#courses', '');

        if (!batchId) {
            return;
        }

        loadOptions(
            'ajax.php',
            {
                action: 'semesters',
                parentid: batchId
            },
            '#semester',
            '-- Select Semester --'
        );
    });

    $('#semester').on('change', function() {

        const semesterId = $(this).val();

        resetSelect('#specialization', '-- Select Specialization --');
        resetSelect('#courses', '');

        if (!semesterId) {
            return;
        }

        loadOptions(
            'ajax.php',
            {
                action: 'specializations',
                parentid: semesterId
            },
            '#specialization',
            '-- Select Specialization --'
        );
    });

    $('#specialization').on('change', function() {

        const specializationId = $(this).val();

        $('#courses')
            .empty()
            .prop('disabled', true);

        if (!specializationId) {
            return;
        }

        $.ajax({
            url: 'ajax.php',
            type: 'GET',
            data: {
                action: 'courses',
                parentid: specializationId
            },
            dataType: 'json',

            success: function(response) {

                $('#courses').empty();

                $.each(response, function(index, course) {

                    $('#courses').append(
                        $('<option>', {
                            value: course.id,
                            text: course.name
                        })
                    );

                });

                $('#courses').prop('disabled', false);
            },

            error: function(xhr) {

                console.error(
                    'Course loading error:',
                    xhr.responseText
                );
            }
        });
    });
    
    $('#courses').on('change', function() {

        const selectedCourses = $(this).val() || [];
        const container = $('#selected-courses');
    
        container.empty();
    
        if (selectedCourses.length === 0) {
            container.text('None');
            return;
        }
    
        const courseNames = [];
    
        $('#courses option:selected').each(function() {
            courseNames.push(
                $(this).text() + ' (ID: ' + $(this).val() + ')'
            );
        });
    
        container.html(
            courseNames
                .map(function(name) {
                    return $('<div>').text(name);
                })
        );
    });
    

    $('#backup-course').on('click', function() {
    
        const selectedCourses = $('#courses').val() || [];
    
        if (selectedCourses.length === 0) {
            alert('Please select at least one course.');
            return;
        }
    
        const button = $(this);
    
        button.prop('disabled', true);
        button.text('Creating Backups...');
    
        let completed = 0;
        let failed = 0;
    
        function processCourse(index) {
    
            if (index >= selectedCourses.length) {
    
                button.prop('disabled', false);
                button.text('Backup Selected Courses');
    
                alert(
                    'Backup process completed. ' +
                    'Successful: ' + completed + '. ' +
                    'Failed: ' + failed + '.'
                );
    
                return;
            }
    
            const courseId = selectedCourses[index];
            
            const courseName = $('#courses option[value="' + courseId + '"]').text();

            button.text('Backing up: ' + courseName);
    
            console.log('Starting backup for course:', courseId);
    
            $.ajax({
                url: 'backup.php',
                type: 'GET',
                data: {
                    courseid: courseId
                },
                dataType: 'json',
    
                success: function(response) {
    
                    if (response.success) {
    
                        completed++;
    
                        console.log(
                            'Backup successful:',
                            response.coursename,
                            response.filename
                        );
    
                    } else {
    
                        failed++;
    
                        console.error(
                            'Backup failed:',
                            response.message
                        );
                    }
    
                    processCourse(index + 1);
                },
    
                error: function(xhr) {
    
                    failed++;
    
                    console.error(
                        'Backup request failed for course:',
                        courseId
                    );
    
                    console.error(
                        xhr.responseText
                    );
    
                    processCourse(index + 1);
                }
            });
        }
    
        processCourse(0);
    });


});
JS
);

echo $OUTPUT->footer();