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
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Task to process logs.
 *
 * @package     tool_s3logs
 * @category    task
 * @copyright   2017 Matt Porritt <mattp@catlayst-au.net>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_s3logs\task;

defined('MOODLE_INTERNAL') || die();

use tool_s3logs\client\s3_client;

/**
 * Class to process logs.
 *
 * @package     tool_s3logs
 * @category    task
 * @copyright   2017 Matt Porritt <mattp@catlayst-au.net>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_logs extends \core\task\scheduled_task {

    /**
     * {@inheritDoc}
     * @see \core\task\scheduled_task::get_name()
     */
    public function get_name() {
        // Shown in admin screens
        return get_string('processlogs', 'tool_s3logs');
    }

    /**
     *
     * {@inheritDoc}
     * @see \core\task\task_base::execute()
     */
    public function execute() {
        global $DB;
        $config = get_config('tool_s3logs');

        // Set up basic vars.
        $maxage = 60 * 60 * 24 * 30 * $config->maxlogage; // We standardise on a month having 30 days.
        $threshold = time() - $maxage;
        $stopat = time() + $config->maxruntime;
        $start = 0;
        $limit = 1000;
        $step = 1000;
        $isempty = True;
 
        // Initialise an S3 client.
        $s3client = new s3_client();

        // Get a temp file.
        $tempdir = make_temp_directory('s3logs_upload');
        $tempfile = tempnam ($tempdir, 's3logs_');
        error_log($tempfile);
        $fp = fopen($tempfile, 'w');

        // Add the table headers to the temp file
        $headerrecords = $DB->get_columns('logstore_standard_log');
        $headers = array();
        foreach ($headerrecords as $key => $value) {
            $headers[] = $key;
        }
        fputcsv($fp, $headers);

        // Get 1000 rows of data from the log table order by oldest first.
        // Keep getting records 1000 at a time until we run out of records or max execution time is reached.
        while (time() <= $stopat){
            $results = $DB->get_records_select(
                    'logstore_standard_log',
                    'timecreated >= ?',
                    array($threshold),
                    'timecreated ASC',
                    '*',
                    $start,
                    $limit
                    );

            if (empty($results)) {
                mtrace('breaking no more results');
                break; // Stop trying to get records when we run out;
            }
            $isempty = false; // We have content for file
            $start += $step;

            // We do not want to load all results into memory,
            // we want to write them to a file as we go.
            foreach($results as $key => $value){
               // error_log(print_r((array)$value, true));
                fputcsv($fp, (array)$value);
            }

        }
        fclose($fp); // Close file now that we have it

        if (!$isemmpty) {
            // if file isn't empty upload this file to s3
            $result = $s3client->client->putObject(array(
                    'Bucket'       => $s3client->bucket,
                    'Key'          => 'foobar',
                    'SourceFile'   => $tempfile,
                    'ContentType'  => 'text/csv'

            ));
            echo $result['ObjectURL'];
        }
        
        // remove entries from log table
        // if we are deleting and if file was happ

    }
} 