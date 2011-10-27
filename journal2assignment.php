<?php  // $Id: journal2assignment.php,v 1.8 2008/06/12 10:35:11 thepurpleblob Exp $
/*********************************************
 *
 * Convert Journals to Online Assignments
 *
 * INSTRUCTIONS:
 * Place this file in the root of your Moodle
 * site. Run the script from your browser.
 * You need to be an admin.
 * Note that this does NOT disable Journals.
 * You can do that in Site Administration
 * once you are sure it worked.
 * Tested on Moodle 1.9.
 *
 * Howard Miller & Mattew Davidson 
 * Original blagged from
 * Assignment upgrade code and modernised. 
 *
 * Update to 2.0
 *********************************************/
define("DEBUG",0);

require_once('config.php');
require_once($CFG->dirroot.'/course/lib.php');
require_once($CFG->libdir.'/gradelib.php');

global $CFG,$USER,$DB;

require_login();
require_capability('moodle/site:config', get_context_instance(CONTEXT_SYSTEM));

$PAGE->set_url('/journal2assignment.php');
$PAGE->set_context(get_context_instance(CONTEXT_SYSTEM));
$PAGE->set_course($SITE);
$PAGE->set_pagetype('journal-conversion');
$PAGE->set_docs_path('');
$PAGE->set_pagelayout('standard');
$PAGE->navbar->add('Migrate Journals');
$PAGE->set_title($SITE->fullname.'Migrate Journals');
$PAGE->set_heading($SITE->fullname.'Migrate Journals');

echo $OUTPUT->header();

$affectedcourses = array();                                        

if (!$journals = $DB->get_records('journal')) {
    error( "Error reading journals from database" );
    die;
}

if (!$assignmentmodule = $DB->get_record('modules',array('name'=>'assignment'))) {
    error( "Error reading assignment module from database" );
}

// track courses that we modify
$affectedcourses = array();

// cycle through journals
$success=0;
foreach ($journals as $journal) {

    // get the course info
    $courseid = $journal->course;

    if ($course = $DB->get_record('course',array('id'=>$courseid))) 
    {
        echo "<h2>Converting Journal '{$journal->name}' ({$journal->id})</h2><p>";
        
        // note the course modified
        $affectedcourses[$courseid] = $courseid;
    
        // First create the assignment instance
        $assignment = new object();
        $assignment->course = $journal->course;
        $assignment->name = addslashes($journal->name);
        $assignment->intro = addslashes(format_text($journal->intro,$journal->introformat));
        $assignment->introformat = FORMAT_HTML;
        $assignment->assignmenttype = 'online';
        $assignment->resubmit = 1;
        $assignment->preventlate = 0;
        $assignment->emailteachers = 0;
        $assignment->var1 = 1;
        $assignment->var2 = 0;
        $assignment->var3 = 0;
        $assignment->var4 = 0;
        $assignment->var5 = 0;
        $assignment->maxbytes = 0;
        if ($journal->days==0) {
            $assignment->timedue = 0;
        }
        else {
            $assignment->timedue = $course->startdate + ($journal->days * 60 * 60); 
        }
    
        $assignment->timeavailable = $course->startdate;
        $assignment->grade = $journal->assessed;
        $assignment->timemodified = $journal->timemodified;
        if($assignment->id = $DB->insert_record('assignment', $assignment)) {
        	echo "Created Assignment '{$assignment->name}' ({$assignment->id}) <br />";
        }else{
        	error("Could not add assginment");
        }
        
        // Now create a new course module record
        $oldcm = get_coursemodule_from_instance('journal', $journal->id, $journal->course);
        
        if($oldcm)
        {
        	$newcm = clone($oldcm);
            $newcm->module   = $assignmentmodule->id; 
            $newcm->instance = $assignment->id;
            $newcm->added    = time();
    
            if (! $newcm->id = add_course_module($newcm) ) {
                echo "Created course module '{$newcm->id}' <br />";
            }else{
            	error("Could not add a new course module");
            }
            
            $assignment->cmidnumber = $newcm->id;
    		
       		$params = array('itemname'=>$assignment->name, 'idnumber'=>$assignment->cmidnumber);
			$grades = NULL;
			
		    if ($assignment->grade > 0) {
		        $params['gradetype'] = GRADE_TYPE_VALUE;
		        $params['grademax']  = $assignment->grade;
		        $params['grademin']  = 0;
		
		    } else if ($assignment->grade < 0) {
		        $params['gradetype'] = GRADE_TYPE_SCALE;
		        $params['scaleid']   = -$assignment->grade;
		
		    } else {
		        $params['gradetype'] = GRADE_TYPE_TEXT; // allow text comments only
		    }
		
		    if ($grades  === 'reset') {
		        $params['reset'] = true;
		        $grades = NULL;
		    }
            
            // create the grade item entry for this assignment
            if ($gradeupdate = grade_update('mod/assignment', $assignment->course, 'mod', 'assignment', $assignment->id, 0, $grades, $params)) {
                echo "Creating grade item response: ".$gradeupdate."<br />";
            }else{
            	error("Error creating grade item response");
            }
            
            // And locate it above the old one
            if (!$section = $DB->get_record('course_sections', array('id'=>$oldcm->section))) {
                $section->section = 0;  // So it goes somewhere!
            }
            
            $newcm->coursemodule = $newcm->id;
            $newcm->section      = $section->section;  // need relative reference
    
            if (! $sectionid = add_mod_to_section($newcm, $oldcm) ) {  // Add it before Journal
                error("Could not add the new course module to that section");
            }
            
            // Convert any existing entries from users
            if ($entries = get_records('journal_entries',array('journal'=>$journal->id))) {
                foreach ($entries as $entry) {
                	echo "Converting Journal Entry {$entry->id}<br />";
                    $submission = new object;
                    $submission->assignment    = $assignment->id;
                    $submission->userid        = $entry->userid;
                    $submission->timecreated   = $entry->modified;
                    $submission->timemodified  = $entry->modified;
                    $submission->numfiles      = 0;
                    $submission->data1         = addslashes( $entry->text );
                    $submission->data2         = $entry->format;
                    $submission->grade         = $entry->rating;
                    $submission->submissioncomment  = addslashes($entry->entrycomment);
                    $submission->format        = FORMAT_MOODLE;
                    $submission->teacher       = $entry->teacher;
                    $submission->timemarked    = $entry->timemarked;
                    $submission->mailed        = $entry->mailed;
    
                    //Get grade item
                    // And locate it above the old one
	                // create the grade item entry for this assignment
		            if ($gradeupdate = grade_update('mod/assignment', $assignment->course, 'mod', 'assignment', $assignment->id, 0, $entry->rating, $params)) {
		                echo "Updating grade item response: ".$gradeupdate." (Grade: {$entry->rating})<br />";
		            }else{
		            	error("Error updating grade");
		            }                  
    
                    if ($submission->id = $DB->insert_record('assignment_submissions', $submission)) {
                    	echo "Inserted submission: {$submission->id}<br />";
                    }else{
                        error( 'Unable to insert assignment submission' );
                    }
                }
            }
            
            echo " -- SUCCESS</p>"; 
            $success++;
        }
        else {
            echo "<div style=\"color:red;\">Journal '{$journal->name}' Course Instance Not Found -- <b>FAILED</b></div>";
        }
    }
}

// Clear the cache so this stuff appears
foreach ($affectedcourses as $courseid) {
    rebuild_course_cache($courseid);
}

echo <<<EOT
    <p><b>Migration of <b>$success</b> journals successfully completed</b></p>
    <p>Note that Journals and Assignments are now duplicated. Please check that
       the activities have transferred correctly and then disable (or delete)
       Journals in Site Administration => Modules => Activities =>
       Manage Activities.</p>
EOT;

echo $OUTPUT->footer();