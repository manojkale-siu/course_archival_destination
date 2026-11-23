<?php

require_once(__DIR__ . '/../../config.php');

require_login();

header('Content-Type: application/json');

$action = required_param('action', PARAM_ALPHA);
$parentid = optional_param('parentid', 0, PARAM_INT);

switch ($action) {

    case 'batches':

        $records = $DB->get_records(
            'course_categories',
            ['parent' => $parentid],
            'name ASC',
            'id, name'
        );

        break;

    case 'semesters':

        $records = $DB->get_records(
            'course_categories',
            ['parent' => $parentid],
            'name ASC',
            'id, name'
        );

        break;

    case 'specializations':

        $records = $DB->get_records(
            'course_categories',
            ['parent' => $parentid],
            'name ASC',
            'id, name'
        );

        break;

    case 'courses':

        $records = $DB->get_records(
            'course',
            ['category' => $parentid],
            'fullname ASC',
            'id, fullname'
        );

        $result = [];

        foreach ($records as $record) {
            $result[] = [
                'id' => (int) $record->id,
                'name' => $record->fullname,
            ];
        }

        echo json_encode($result);
        exit;

    default:

        http_response_code(400);

        echo json_encode([
            'error' => 'Invalid action',
        ]);

        exit;
}

$result = [];

foreach ($records as $record) {
    $result[] = [
        'id' => (int) $record->id,
        'name' => $record->name,
    ];
}

echo json_encode($result);