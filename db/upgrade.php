<?php

defined('MOODLE_INTERNAL') || die();

function xmldb_local_coursemigrator_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026100301) {

        $table = new xmldb_table('course_migrator_receiver');

        // Fields.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);

        $table->add_field('source_moodle_url', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('source_course_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('source_course_fullname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('source_course_shortname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);

        $table->add_field('destination_course_id', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('destination_course_fullname', XMLDB_TYPE_CHAR, '255');
        $table->add_field('destination_course_shortname', XMLDB_TYPE_CHAR, '255');

        $table->add_field('institute', XMLDB_TYPE_CHAR, '255');
        $table->add_field('program', XMLDB_TYPE_CHAR, '255');
        $table->add_field('batch', XMLDB_TYPE_CHAR, '255');
        $table->add_field('semester', XMLDB_TYPE_CHAR, '255');
        $table->add_field('specialization', XMLDB_TYPE_CHAR, '255');

        $table->add_field('mbz_filename', XMLDB_TYPE_CHAR, '255');

        $table->add_field(
            'restore_status',
            XMLDB_TYPE_CHAR,
            '20',
            null,
            XMLDB_NOTNULL,
            null,
            'pending'
        );

        $table->add_field('restore_message', XMLDB_TYPE_TEXT);
        $table->add_field('restore_logs', XMLDB_TYPE_TEXT);

        $table->add_field('created_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('updated_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);

        // Primary key.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Indexes.
        $table->add_index(
            'source_moodle_url',
            XMLDB_INDEX_NOTUNIQUE,
            ['source_moodle_url']
        );

        $table->add_index(
            'source_course_id',
            XMLDB_INDEX_NOTUNIQUE,
            ['source_course_id']
        );

        $table->add_index(
            'destination_course_id',
            XMLDB_INDEX_NOTUNIQUE,
            ['destination_course_id']
        );

        $table->add_index(
            'restore_status',
            XMLDB_INDEX_NOTUNIQUE,
            ['restore_status']
        );

        // Create table if it does not already exist.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(
            true,
            2026100301,
            'local',
            'coursemigrator'
        );
    }

    return true;
}