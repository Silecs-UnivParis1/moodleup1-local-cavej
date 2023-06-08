<?php
/**
 * Edit course settings
 *
 * @package    local
 * @subpackage cavej
 * @copyright  2012-2013 Silecs {@link http://www.silecs.info/societe}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or laters
 */
class cavej_duplicate_categories {
    public $catsrc;
    public $catdest;
    public $newcategories = array();
    public $correspondance = array();
    public $catwithcourse = array();
    public $oldcategories = array();
    public $olddepth = array();


    public function __construct($catsrc, $catdest) {
        $this->catsrc = $catsrc;
        $this->catdest = $catdest;
        $this->newcategories[$this->catdest->id] = $this->catdest;
        $this->correspondance[$this->catsrc->id] = $this->catdest->id;
        if ($this->catsrc->coursecount > 0) {
            $this->catwithcourse[] = $this->catsrc->id;
        }
    }

    /**
    * renvoie les catégories descendantes de la catégorie $this->catsrc
    * @param object $catsrc
    */
    function get_child_categories() {
        global $DB;
        $select = '*';
        $sql = "SELECT " . $select . " FROM {course_categories} WHERE path LIKE '/" . $this->catsrc->id
            . "%' AND depth > " . $this->catsrc->depth . " ORDER BY sortorder";
        $categories = $DB->get_records_sql($sql);
        if (count($categories)) {
            $this->oldcategories = $categories;
            foreach ($categories as $id => $category) {
                $this->olddepth[$category->depth][] = $id;
            }
        }
    }

    /**
     * duplique l'arbre des catégories de $this->catsrc dans $this->catdest
     */
    public function duplicate_categories() {
        foreach ($this->olddepth as $catsbydepth) {
            foreach ($catsbydepth as $idcat) {
                $cat = $this->oldcategories[$idcat];
                if ($cat->coursecount > 0) {
                    $this->catwithcourse[] = $cat->id;
                }
                $newparent = $this->newcategories[$this->correspondance[$cat->parent]];
                $newcat = cavej_duplicate_category($cat, $newparent);
                $this->newcategories[$newcat->id] = $newcat;
                $this->correspondance[$cat->id] = $newcat->id;
            }
        }
    }

    /**
     * renvoie un message après la création des nouvelles catégories
     * @return string $ms
     */
    public function get_message_duplicate_categories() {
        $ms = '';
        $nbcat = count($this->newcategories) - 1;
        $ms .=  "\n" . $nbcat . " Nouvelle(s) catégorie(s) créée(s)\n";
        return $ms;
    }
}

/**
 * Duplique le cours $course en le rattachant à la catégorie d'identifiant
 * $newidcat
 * @param object $course cours à dupliquer
 * @param int $newidcat
 * @return object $nc le nouveau cours
 */
function cavej_duplicate_course($course, $newidcat) {
    global $DB;
    $shorname = $course->shortname . '-copie';
    $sql = "SELECT count(id) FROM {course} WHERE shortname like ?";
    $nb = $DB->get_field_sql($sql, array($shorname . "%"));

    $newcourse= new stdClass();
    $newcourse = $course;
    $newcourse->shortname = $shorname . ($nb==0?'1':$nb+1);
    $newcourse->category = $newidcat;

    $options = array();
    $options[] = array('name' => 'users', 'value' => 0);
    $duplicate = new cavej_duplicate_course($course->id, $newcourse, $options);
    $duplicate->create_backup();
    $nc = $duplicate->retore_backup();
    return $nc;
}
/**
 * retourne l'objet categorie dont $idcat est l'identifiant
 * @param int $idcat
 * @return ojet categorie ou false
 */
function cavej_get_category($idcat) {
    global $DB;
    return $DB->get_record('course_categories', array('id' => $idcat));
}

/**
 * Vérifie la validité de l'argument $name
 * @param string $name nom de l'argument
 * @param int $value valeur de l'argument
 * @return object/array catégorie ou un tableau d'erreur
 */
function cavej_control_param($name, $value) {
    global $DB;
    $errors = array();
    $value = (int) $value;
    //on vérifie que c'est bien un int
    if ($value === 0) {
        $errors['error'] = "Le paramètre " . $name . " n'est pas un entier\n";
        return $errors;
    }
    //on verifie que la catégorie existe bien
    $category = cavej_get_category($value);
    if ($category == FALSE) {
        $errors['error'] = "Le paramètre " . $name . " ne correspond à aucune catégorie\n";
        return $errors;
    }
    //si $name = catdest, vérifier que la catégorie est vide;
    if ($name == 'catdest') {
        $sql = "SELECT id FROM {course_categories} WHERE path LIKE '/" . $value
            . "%'";
        $childs = $DB->get_records_sql($sql);
        if (count($childs) > 1) {
            $errors['error'] = "La catégorie " . $name . " doit être vide\n";
            return $errors;
        }
    }
    return $category;
}


/**
 * Cré une nouvelle catégorie, copie de $category et catégorie fille
 * de $parent
 * @param object $category categorie à copier
 * @param object $parent catégorie parent de la nouvelle catégorie
 * @return object/bool $mycategory nouvelle catégorie créée ou false
 */
function cavej_duplicate_category($category, $parent) {
    global $DB;
    $newdepth = (int) $parent->depth + 1;
    $newcategory = new stdClass();
    $newcategory->name = $category->name;
    $newcategory->idnumber = '';
    if ($category->idnumber != '') {
        $idbumber = $category->idnumber . '-copie';
        $sql = "SELECT count(id) FROM {course_categories} WHERE idnumber like '" . $idbumber . "%'";
        $nb = $DB->get_field_sql($sql);
        $newcategory->idnumber = $idbumber . ($nb==0?'1':$nb+1);
    }
    //$newcategory->sortorder;
    $newcategory->description = $category->description;
    $newcategory->descriptionformat = $category->descriptionformat;
    $newcategory->depth = $newdepth;
    $newcategory->parent = $parent->id;
    $newcategory->coursecount = $category->coursecount;
    $newcategory->visible = $category->visible;
    $newcategory->visibleold = $category->visibleold;
    //$newcategory->timemodified = time();
    $mycategory = coursecat::create($newcategory);
    return $mycategory;
}

/**
 * Renvoie un tableau des cours des catégories dont les identifiants
 * sont passées dans le tableau $categories
 * @param array $categories tableau d'identifiant de catégories
 * @return array/bool $courses ou false
 */
function cavej_get_categories_whith_courses($categories) {
    global $DB;
    $in = '';
    foreach ($categories as $cat) {
        $in .= $cat . ',';
    }
    $in = substr($in, 0, -1);
    $select = '*';
    $sql = "SELECT " . $select . " FROM {course} WHERE category IN (" . $in . ") ORDER BY sortorder desc";
    $courses = $DB->get_records_sql($sql);
    return $courses;
}

/**
 * copie tous les enrolments de type cohort et manual de $oldcourse dans $newcourse
 * @param object $oldcourse
 * @param object $newcourse
 */
function cavej_enrolment($oldcourse, $newcourse) {
    global $DB, $CFG;
    $DB->delete_records('enrol', array('courseid' => $newcourse->id));

    // anciens enrolments
    $sql = "SELECT * FROM {enrol} WHERE courseid=" . $oldcourse->id . " AND status=0 "
        . " AND enrol IN ('cohort', 'manual') ORDER BY sortorder";
    $enrols = $DB->get_records_sql($sql);

    if ($enrols == FALSE || count($enrols)==0) {
        return 0;
    }
    $oldcontext = context_course::instance($oldcourse->id);
    foreach ($enrols as $enrol) {
        $enrol->courseid = $newcourse->id;
        $enrol->timemodified = time();
        $enrol->timecreated = $enrol->timemodified;
        $enrol->sortorder = $DB->get_field('enrol', 'COALESCE(MAX(sortorder), -1) + 1', array('courseid' => $newcourse->id));
        $DB->insert_record('enrol', $enrol);
        if ($enrol->enrol == 'cohort') {
            require_once("$CFG->dirroot/enrol/cohort/locallib.php");
            $trace = new null_progress_trace();
            enrol_cohort_sync($trace, $newcourse->id);
        } elseif ($enrol->enrol == 'manual') {
            require_once("$CFG->dirroot/lib/enrollib.php");
            $sql = "SELECT * FROM {user_enrolments} WHERE status=0 AND enrolid=" . $enrol->id;
            $manuals = $DB->get_records_sql($sql);
            if ($manuals != FALSE && count($manuals)) {
                foreach ($manuals as $manual) {
                    $sql = "select roleid from {role_assignments} WHERE userid=".$manual->userid." AND contextid=" . $oldcontext->id;
                    $roleid = $DB->get_field_sql($sql);
                    if ($roleid) {
                        enrol_try_internal_enrol($newcourse->id, $manual->userid, $roleid);
                    }
                }
            }
        }
    }
}

/**
 * patch permettant de modifier les shortname de $oldcourse et $newcourse
 */
function cavej_rename_shortname($oldcourse, $newcourse, $shortname) {
    global $DB;
    $curyear = get_config('local_cohortsyncup1', 'cohort_period');
    $oldname = $shortname .  $curyear;

    $DB->execute("UPDATE {course} SET shortname = ? WHERE id = ?", array($oldname, $oldcourse->id));
    $DB->execute("UPDATE {course} SET shortname = ? WHERE id = ?", array($shortname, $newcourse->id));
}
