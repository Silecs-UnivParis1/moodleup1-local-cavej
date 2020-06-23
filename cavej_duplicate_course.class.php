<?php
/**
 * Edit course settings
 *
 * @package    local
 * @subpackage cavej
 * @copyright  2012-2013 Silecs {@link http://www.silecs.info/societe}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or laters
 */

/**
 * cette classe utilise le compte "administrateur principal" (renvoyé par
 * get_admin()) pour créer le backup et effectuer la restauration. Besoin des
 * permissions moodle/course:create, moodle/restore:restorecourse et
 * moodle/backup:backupcourse au niveau de la plateforme
 */
class cavej_duplicate_course {

    protected $adminuser;
    public $courseid;
    public $newcoursedata;
    public $backupid;
    public $backupbasepath;
    public $file;
    public $backupsettings = array();

    public $backupdefaults = array(
        'activities' => 1,
        'blocks' => 1,
        'filters' => 1,
        'users' => 0,
        'role_assignments' => 0,
        'comments' => 0,
        'userscompletion' => 0,
        'logs' => 0,
        'grade_histories' => 0
    );

    public function __construct($courseid, $mydata, $options) {
        $this->courseid = $courseid;
        $this->mydata = $mydata;
        $this->options = $options;
        $this->adminuser = get_admin();
    }

    public function create_backup() {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

        // Check for backup and restore options.
        if (!empty($this->options)) {
            foreach ($this->options as $option) {

                // Strict check for a correct value (allways 1 or 0, true or false).
                $value = clean_param($option['value'], PARAM_INT);

                if ($value !== 0 and $value !== 1) {
                    throw new moodle_exception('invalidextparam', 'webservice', '', $option['name']);
                }

                if (!isset($this->backupdefaults[$option['name']])) {
                    throw new moodle_exception('invalidextparam', 'webservice', '', $option['name']);
                }

                $this->backupsettings[$option['name']] = $value;
            }
        }

        $bc = new backup_controller(backup::TYPE_1COURSE, $this->courseid, backup::FORMAT_MOODLE,
        backup::INTERACTIVE_NO, backup::MODE_SAMESITE, $this->adminuser->id);

        foreach ($this->backupsettings as $name => $value) {
            $bc->get_plan()->get_setting($name)->set_value($value);
        }

        $this->backupid       = $bc->get_backupid();
        $this->backupbasepath = $bc->get_plan()->get_basepath();

   //     echo "backupid : " . $this->backupid  . "\n";
     //   echo"backupbasepath : " .  $this->backupbasepath . "\n";


        $bc->execute_plan();
        $results = $bc->get_results();
        $this->file = $results['backup_destination'];

        $bc->destroy();

    }


    public function retore_backup() {
        global $CFG,$DB;
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        // Check if we need to unzip the file because the backup temp dir does not contains backup files.
        if (!file_exists($this->backupbasepath . "/moodle_backup.xml")) {
            $this->file->extract_to_pathname(get_file_packer('application/x-gzip'), $this->backupbasepath);
        }

        $newcourseid = restore_dbops::create_new_course($this->mydata->fullname,
            $this->mydata->shortname, $this->mydata->category);

        $rc = new restore_controller($this->backupid, $newcourseid,
                backup::INTERACTIVE_NO, backup::MODE_SAMESITE, $this->adminuser->id, backup::TARGET_NEW_COURSE);

        foreach ($this->backupsettings as $name => $value) {
            $setting = $rc->get_plan()->get_setting($name);
            if ($setting->get_status() == backup_setting::NOT_LOCKED) {
                $setting->set_value($value);
            }
        }

        if (!$rc->execute_precheck()) {
            $precheckresults = $rc->get_precheck_results();
            if (is_array($precheckresults) && !empty($precheckresults['errors'])) {
                if (empty($CFG->keeptempdirectoriesonbackup)) {
                    fulldelete($backupbasepath);
                }

                $errorinfo = '';

                foreach ($precheckresults['errors'] as $error) {
                    $errorinfo .= $error;
                }

                if (array_key_exists('warnings', $precheckresults)) {
                    foreach ($precheckresults['warnings'] as $warning) {
                        $errorinfo .= $warning;
                    }
                }

                throw new moodle_exception('backupprecheckerrors', 'webservice', '', $errorinfo);
            }
        }

        $rc->execute_plan();
        $rc->destroy();

        $course = $DB->get_record('course', array('id' => $newcourseid), '*', MUST_EXIST);

        $course->fullname = $this->mydata->fullname;
        $course->shortname = $this->mydata->shortname;
        if (isset($this->mydata->idnumber)) {
            $course->idnumber = $this->mydata->idnumber;
        }
        $course->visible = $this->mydata->visible;
      /**  $course->startdate = $this->mydata->startdate;
        $course->summary       = $this->mydata->summary_editor['text'];
        $course->summaryformat = $this->mydata->summary_editor['format'];
        **/

        // Set shortname and fullname back.
        $DB->update_record('course', $course);

        if (empty($CFG->keeptempdirectoriesonbackup)) {
            fulldelete($this->backupbasepath);
        }

        // Delete the course backup file created by this WebService. Originally located in the course backups area.
        $this->file->delete();

        return $course;

    }
}
