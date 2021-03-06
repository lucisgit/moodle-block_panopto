<?php

/* Copyright Panopto 2009 - 2013 / With contributions from Spenser Jones (sjones@ambrose.edu)
 *
 * This file is part of the Panopto plugin for Moodle.
 *
 * The Panopto plugin for Moodle is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * The Panopto plugin for Moodle is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with the Panopto plugin for Moodle.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Panopto block data classes.
 *
 * @package     block_panopto
 * @copyright   Panopto 2009 - 2013 / With contributions from Spenser Jones (sjones@ambrose.edu)
 * @license     http://www.gnu.org/licenses/lgpl.html GNU LGPL
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Panopto data class.
 *
 * Provides data communication with Panopto server.
 *
 * @package     block_panopto
 * @copyright   Panopto 2009 - 2013 / With contributions from Spenser Jones (sjones@ambrose.edu)
 * @license     http://www.gnu.org/licenses/lgpl.html GNU LGPL
 */
class panopto_data {

    var $instancename;
    var $moodle_course_id;
    var $servername;
    var $applicationkey;
    /** @var stdClass Keeps PanoptoSoapClient object */
    private $soap_client;
    var $sessiongroup_id;
    var $uname;

    /**
     * The class constructor.
     *
     * @param int $moodle_course_id course id in Moodle
     */
    function __construct($moodle_course_id = null) {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/blocks/panopto/lib.php');
        require_once("PanoptoSoapClient.php");

        // Fetch global settings from DB
        $this->instancename = get_config('block_panopto', 'instance_name');

        if (!empty($moodle_course_id)) {
            // First make sure that course exists in Moodle.
            $course = $DB->get_record('course', array('id'=>$moodle_course_id), 'id,category', MUST_EXIST);
            $this->moodle_course_id = $course->id;

            // If course has been provisioned, define its panopto data.
            if ($panoptofoldermap = $DB->get_record('block_panopto_foldermap', array('moodleid' => $course->id))) {
                $this->servername = $panoptofoldermap->panopto_server;
                $this->applicationkey = $panoptofoldermap->panopto_app_key;
                $this->sessiongroup_id = $panoptofoldermap->panopto_id;
            }
        }
    }

    /**
     * Checks if course has been provisioned.
     *
     * @return bool $isprovisioned True if course has been provisioned, false otherwise.
     */
    function is_provisioned() {
        if (empty($this->servername) || empty($this->applicationkey) || empty($this->sessiongroup_id)) {
            return false;
        }
        return true;
    }

    // returns SystemInfo
    function get_system_info() {
        $soapclient = $this->get_soap_client();
        return $soapclient->GetSystemInfo();
    }

    // Create the Panopto course and populate its ACLs.
    function provision_course($provisioning_info) {
        $soapclient = $this->get_soap_client();
        $course_info = $soapclient->ProvisionCourse($provisioning_info);

        if (!empty($course_info) && !empty($course_info->PublicID)) {
            panopto_data::set_panopto_course_id($this->moodle_course_id, $course_info->PublicID);
            $this->sessiongroup_id = $course_info->PublicID;
            panopto_data::set_panopto_server_name($this->moodle_course_id, $this->servername);
            panopto_data::set_panopto_app_key($this->moodle_course_id, $this->applicationkey);
        }

        return $course_info;
    }

    // Fetch course name and membership info from DB in preparation for provisioning operation.
    function get_provisioning_info() {

        global $DB;
        $provisioning_info = new stdClass();
        $provisioning_info->ShortName = $DB->get_field('course', 'shortname', array('id' => $this->moodle_course_id));
        $provisioning_info->LongName = $DB->get_field('course', 'fullname', array('id' => $this->moodle_course_id));
        $provisioning_info->ExternalCourseID = $this->instancename . ":" . $this->moodle_course_id;
        $provisioning_info->Server = $this->servername;
        $course_context = context_course::instance($this->moodle_course_id, MUST_EXIST);

        // Lookup table to avoid adding instructors as Viewers as well as Creators.
        $publisher_hash = array();
        $instructor_hash = array();

        $publishers = get_users_by_capability($course_context, 'block/panopto:provision_aspublisher');

        if (!empty($publishers)) {
            $provisioning_info->Publishers = array();
            foreach ($publishers as $publisher) {
                $publisher_info = new stdClass;
                $publisher_info->UserKey = $this->panopto_decorate_username($publisher->username);
                $publisher_info->FirstName = $publisher->firstname;
                $publisher_info->LastName = $publisher->lastname;
                $publisher_info->Email = $publisher->email;

                array_push($provisioning_info->Publishers, $publisher_info);

                $publisher_hash[$publisher->username] = true;
            }
        }


        // moodle/course:update capability will include admins along with teachers, course creators, etc.
        // Could also use moodle/legacy:teacher, moodle/legacy:editingteacher, etc. if those turn out to be more appropriate.
        // File edited - new capability added to access.php to identify instructors without including all site admins etc.
        // New capability used to identify instructors for provisioning.
        $instructors = get_users_by_capability($course_context, 'block/panopto:provision_asteacher');

        if (!empty($instructors)) {
            $provisioning_info->Instructors = array();
            foreach ($instructors as $instructor) {
                if (array_key_exists($instructor->username, $publisher_hash))
                    continue;
                $instructor_info = new stdClass;
                $instructor_info->UserKey = $this->panopto_decorate_username($instructor->username);
                $instructor_info->FirstName = $instructor->firstname;
                $instructor_info->LastName = $instructor->lastname;
                $instructor_info->Email = $instructor->email;

                array_push($provisioning_info->Instructors, $instructor_info);

                $instructor_hash[$instructor->username] = true;
            }
        }

        // Give all enrolled users at least student-level access. Instructors will be filtered out below.
        // Use get_enrolled_users because, as of Moodle 2.0, capability moodle/course:view no longer corresponds to a participant list.
        $students = get_enrolled_users($course_context);

        if (!empty($students)) {
            $provisioning_info->Students = array();
            foreach ($students as $student) {
                if (array_key_exists($student->username, $instructor_hash))
                    continue;
                if (array_key_exists($student->username, $publisher_hash))
                    continue;
                $student_info = new stdClass;
                $student_info->UserKey = $this->panopto_decorate_username($student->username);
                $student_info->FirstName = $student->firstname;
                $student_info->LastName = $student->lastname;
                $student_info->Email = $student->email;

                array_push($provisioning_info->Students, $student_info);
            }
        }

        return $provisioning_info;
    }

    // Get courses visible to the current user.
    function get_courses() {
        $soapclient = $this->get_soap_client();

        $courses_result = $soapclient->GetCourses();
        $courses = array();
        if (!empty($courses_result->CourseInfo)) {
            $courses = $courses_result->CourseInfo;
            // Single-element return set comes back as scalar, not array (?)
            if (!is_array($courses)) {
                $courses = array($courses);
            }
        }

        return $courses;
    }

    // Get info about the currently mapped course.
    function get_course() {
        $soapclient = $this->get_soap_client();
        return $soapclient->GetCourse($this->sessiongroup_id);
    }

    // Get ongoing Panopto sessions for the currently mapped course.
    function get_live_sessions() {
        $soapclient = $this->get_soap_client();
        $live_sessions_result = $soapclient->GetLiveSessions($this->sessiongroup_id);

        $live_sessions = array();
        if (!empty($live_sessions_result->SessionInfo)) {
            $live_sessions = $live_sessions_result->SessionInfo;
            // Single-element return set comes back as scalar, not array (?)
            if (!is_array($live_sessions)) {
                $live_sessions = array($live_sessions);
            }
        }

        return $live_sessions;
    }

    // Get recordings available to view for the currently mapped course.
    function get_completed_deliveries() {
        $soapclient = $this->get_soap_client();
        $completed_deliveries_result = $soapclient->GetCompletedDeliveries($this->sessiongroup_id);

        $completed_deliveries = array();
        if (!empty($completed_deliveries_result->DeliveryInfo)) {
            $completed_deliveries = $completed_deliveries_result->DeliveryInfo;
            // Single-element return set comes back as scalar, not array (?)
            if (!is_array($completed_deliveries)) {
                $completed_deliveries = array($completed_deliveries);
            }
        }

        return $completed_deliveries;
    }

    // Instance method caches Moodle instance name from DB (vs. lib.php version).
    function panopto_decorate_username($moodle_username) {
        return ($this->instancename . "\\" . $moodle_username);
    }

    static function get_course_role_mappings($moodle_course_id) {
        global $DB;
        $pubroles = array();
        $creatorroles = array();
         //get publisher roles as string and explode to array
        $rolesraw = $DB->get_record('block_panopto_foldermap', array('moodleid' => $moodle_course_id), 'publisher_mapping, creator_mapping');
        if ($rolesraw && !empty($rolesraw->publisher_mapping)) {
            $pubroles = explode("," , $rolesraw->publisher_mapping);
        }
        if ($rolesraw && !empty($rolesraw->creator_mapping)) {
            $creatorroles = explode(",", $rolesraw->creator_mapping);
        }
        //get publisher roles as string and explode to array
        return array("publisher" => $pubroles, "creator" => $creatorroles);
    }

    // Called by Moodle block instance config save method, so must be static.
    static function set_panopto_course_id($moodle_course_id, $sessiongroup_id) {
        global $DB;
        if ($DB->get_records('block_panopto_foldermap', array('moodleid' => $moodle_course_id))) {
            return $DB->set_field('block_panopto_foldermap', 'panopto_id', $sessiongroup_id, array('moodleid' => $moodle_course_id));
        } else {
            $row = (object) array('moodleid' => $moodle_course_id, 'panopto_id' => $sessiongroup_id);
            return $DB->insert_record('block_panopto_foldermap', $row);
        }
    }

    static function set_panopto_server_name($moodle_course_id, $panopto_servername) {
        global $DB;
        if ($DB->get_records('block_panopto_foldermap', array('moodleid' => $moodle_course_id))) {
            return $DB->set_field('block_panopto_foldermap', 'panopto_server', $panopto_servername, array('moodleid' => $moodle_course_id));
        } else {
            $row = (object) array('moodleid' => $moodle_course_id, 'panopto_server' => $panopto_servername);
            return $DB->insert_record('block_panopto_foldermap', $row);
        }
    }

    static function set_panopto_app_key($moodle_course_id, $panopto_appkey) {
        global $DB;
        if ($DB->get_records('block_panopto_foldermap', array('moodleid' => $moodle_course_id))) {
            return $DB->set_field('block_panopto_foldermap', 'panopto_app_key', $panopto_appkey, array('moodleid' => $moodle_course_id));
        } else {
            $row = (object) array('moodleid' => $moodle_course_id, 'panopto_app_key' => $panopto_appkey);
            return $DB->insert_record('block_panopto_foldermap', $row);
        }
    }

    static function set_course_role_mappings($moodle_course_id, $publisherroles, $creatorroles) {
        global $DB;

        //implode roles to string
        $publisher_role_string = implode(',', $publisherroles);

        if ($DB->get_records('block_panopto_foldermap', array('moodleid' => $moodle_course_id))) {
            $pubsuccess = $DB->set_field('block_panopto_foldermap', 'publisher_mapping', $publisher_role_string, array('moodleid' => $moodle_course_id));
        } else {
            $row = (object) array('moodleid' => $moodle_course_id, 'publisher_mapping' => $publisher_role_string);
            $pubsuccess = $DB->insert_record('block_panopto_foldermap', $row);
        }

        //implode roles to string
        $creator_role_string = implode(',', $creatorroles);

        if ($DB->get_records('block_panopto_foldermap', array('moodleid' => $moodle_course_id))) {
            $csuccess = $DB->set_field('block_panopto_foldermap', 'creator_mapping', $creator_role_string, array('moodleid' => $moodle_course_id));
        } else {
            $row = (object) array('moodleid' => $moodle_course_id, 'creator_mapping' => $creator_role_string);
            $csuccess = $DB->insert_record('block_panopto_foldermap', $row);
        }
    }

    function get_course_options() {
        $courses_by_access_level = array("Creator" => array(), "Viewer" => array(), "Public" => array());

        $panopto_courses = $this->get_courses();
        if (!empty($panopto_courses)) {
            foreach ($panopto_courses as $course_info) {
                array_push($courses_by_access_level[$course_info->Access], $course_info);
            }

            $options = array();
            foreach (array_keys($courses_by_access_level) as $access_level) {
                $courses = $courses_by_access_level[$access_level];
                $group = array();
                foreach ($courses as $course_info) {
                    $display_name = s($course_info->DisplayName);
                    $group[$course_info->PublicID] = $display_name;
                }
                $options[$access_level] = $group;
            }
        } else if (isset($panopto_courses)) {
            $options = array('Error' => array('-- No Courses Available --'));
        } else {
            $options = array('Error' => array('!! Unable to retrieve course list !!'));
        }

        return array('courses' => $options, 'selected' => $this->sessiongroup_id);
    }

    function add_course_user($role, $userkey) {
        $soapclient = $this->get_soap_client();
        $result = null;
        try {
            $result = $soapclient->AddUserToCourse($this->sessiongroup_id, $role, $userkey);
        } catch (Exception $e) {
            error_log("Error: " . $e->getMessage());
            error_log("Code: " . $e->getCode());
            error_log("Line: " . $e->getLine());
        }
        return $result;
    }

    function remove_course_user($role, $userkey) {
        $soapclient = $this->get_soap_client();
        $result = null;
        try {
            $result = $soapclient->RemoveuserFromCourse($this->sessiongroup_id, $role, $userkey);
        } catch (Exception $e) {
            error_log("Error: " . $e->getMessage());
            error_log("Code: " . $e->getCode());
            error_log("Line: " . $e->getLine());
        }
        return $result;
    }

    function change_user_role($role, $userkey) {
        $soapclient = $this->get_soap_client();
        $result = null;
        try {
            $result = $soapclient->ChangeUserRole($this->sessiongroup_id, $role, $userkey);
        } catch (Exception $e) {
            error_log("Error: " . $e->getMessage());
            error_log("Code: " . $e->getCode());
            error_log("Line: " . $e->getLine());
        }
        return $result;
    }

    //Used to instantiate a soap client for a given instance of panopto_data. Should be called only the first time a soap client is needed for an instance
    function instantiate_soap_client($username, $servername, $applicationkey) {
        global $USER;
        if (!empty($this->servername)) {
            if (isset($USER->username)) {
                $username = $USER->username;
            } else {
                $username = "guest";
            }
            $this->uname = $username;
        }
        // Compute web service credentials for current user.
        $apiuser_userkey = block_panopto_decorate_username($username);
        $apiuser_authcode = block_panopto_generate_auth_code($apiuser_userkey . "@" . $this->servername, $this->applicationkey);

        // Instantiate our SOAP client.
        return new PanoptoSoapClient($this->servername, $apiuser_userkey, $apiuser_authcode);
    }


    /**
     * Return Panopto role for a user, given a context.
     *
     * @param context $context Moodle context
     * @param int $userid Moodle user id
     * @return string Panopto role
     */
    function get_role_from_context($context, $userid) {
        if (has_capability('block/panopto:provision_aspublisher', $context, $userid)) {
            return "Publisher";
        } else if (has_capability('block/panopto:provision_asteacher', $context, $userid)) {
            return "Creator";
        } else {
            return "Viewer";
        }
    }

    /**
     * Get SOAP client instance.
     *
     * @return stdClass PanoptoSoapClient
     */
    function get_soap_client() {
        if (!isset($this->soap_client)) {
            $this->soap_client = $this->instantiate_soap_client($this->uname, $this->servername, $this->applicationkey);
        }
        return $this->soap_client;
    }

}