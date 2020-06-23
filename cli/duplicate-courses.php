<?php

define('CLI_SCRIPT', true);
require(dirname(dirname(dirname(__DIR__))).'/config.php'); // global moodle config file.
require_once($CFG->libdir.'/clilib.php');
require_once($CFG->dirroot . "/lib/coursecatlib.php");
require(dirname(__DIR__) . '/cavej_duplicate_course.class.php');
require(dirname(__DIR__) . '/cavejlib.php');


// now get cli options
list($options, $unrecognized) = cli_get_params(array(
        'help'=>false, 'verb'=>1,
        'duplication'=>false,
        'catsrc'=>false,
        'catdest'=>false,
    ),
    array('h'=>'help', 'd'=>'duplication'));

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

$help =
"Create or delete index pages according to the ROF cache and Course categories

Options:
-h, --help            Print out this help
--verb=N              Verbosity (0 to 3), 1 by default

--catsrc=N            Identifiant de la catégorie source (un entier positif)
--catdest=N           Identifiant de la catégorie cible (un entier positif). Cette catégorie doit être vide

--duplication         Duplication des cours

";

if ( ! empty($options['help']) ) {
    echo $help;
    return 0;
}

$CFG->debug = DEBUG_NORMAL;

if ( $options['duplication'] ) {
    if (empty($options['catsrc'])) {
        echo "--catsrc=N  manque\n";
        return 0;
    }
    if (empty($options['catdest'])) {
        echo "--catdest=N  manque\n";
        return 0;
    }

    $catsrc = cavej_control_param('catsrc', $options['catsrc']);
    if (is_array($catsrc) == TRUE) {
        echo $catsrc['error'];
        return 0;
    }
    $catdest = cavej_control_param('catdest', $options['catdest']);
    if (is_array($catdest) == TRUE) {
        echo $catdest['error'];
        return 0;
    }

    $mydup = new cavej_duplicate_categories($catsrc, $catdest);
    $mydup->get_child_categories($catsrc);
    // copie des catégories
    if (count($mydup->oldcategories)) {
        $mydup->duplicate_categories();
    }
    echo $mydup->get_message_duplicate_categories();

    echo "\n\nDébut des copies de cours... \n\n";

    $oldcourse = cavej_get_categories_whith_courses($mydup->catwithcourse);
    $nbcourse = 0;
    if ($oldcourse) {
        foreach ($oldcourse as $course) {
            $sortname = $course->shortname;
            $newcourse = cavej_duplicate_course($course, $mydup->correspondance[$course->category]);
            if ($newcourse) {
                cavej_rename_shortname($course, $newcourse, $sortname);
                cavej_enrolment($course, $newcourse);
                ++$nbcourse;
                echo "\n" . $nbcourse . " : Création du cours [".$newcourse->id."] : " . $sortname . "\n\n";
            } else {
                echo "\n ATTENTION : Problème avec le cours [" . $course->id . "] - " . $sortname . "\n\n";
            }
        }
    }
    echo "\n" . $nbcourse . " cours créé(s)\n";
    return 0;
}

echo "\nfin\n";
