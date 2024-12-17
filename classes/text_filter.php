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
 * Filter converting medial texts into images
 *
 * This filter uses the medial settings in Site admin > Appearance > HTML settings
 * and replaces medial texts with images.
 *
 * @package    filter
 * @subpackage medial
 * @see        medial_manager
 * @copyright  2010 David Mudrak <david@moodle.com>, 2020 MEDIAL
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace filter_medial;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/mod/helixmedia/locallib.php');

class text_filter extends \moodle_text_filter {

    /**
     * Internal cache used for replacing. Multidimensional array;
     * - dimension 1: language,
     * - dimension 2: theme.
     * @var array
     */
    protected static $medialtexts = array();

    /**
     * Internal cache used for replacing. Multidimensional array;
     * - dimension 1: language,
     * - dimension 2: theme.
     * @var array
     */
    protected static $medialimgs = array();

    /**
     * Apply the filter to the text
     *
     * @see filter_manager::apply_filter_chain()
     * @param string $text to be processed by the text
     * @param array $options filter options
     * @return string text after processing
     */
    public function filter($text, array $options = array()) {
        if (!isset($options['originalformat'])) {
            // If the format is not specified, we are probably called by {@see format_string()}.
            // In that case, it would be dangerous to replace text with the image because it could
            // be stripped. therefore, we do nothing.
            return $text;
        }
        if (in_array($options['originalformat'], explode(',', get_config('filter_medial', 'formats')))) {
            return $this->replace_medials($text);
        }
        return $text;
    }

    /**
     * Replace medials found in the text with their images
     *
     * @param string $text to modify
     * @return string the modified result
     */
    protected function replace_medials($text) {
        global $CFG, $OUTPUT, $PAGE;
        // Detect all zones that we should not handle (including the nested tags).
        //$processing = preg_split('/(<\/?(?:span|script)[^>]*>)/is', $text, 0, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $processing = preg_split('/(<[^>]*>)/i', $text, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);

        // Initialize the results.
        $resulthtml = "";
        $exclude = 0;
        $skip = 0;

        // Define the patterns that mark the start of the forbidden zones.
        $excludepattern = array('/^<script/is', '/^<span[^>]+class="nolink[^"]*"/is');

        // Loop through the fragments.
        foreach ($processing as $idx => $fragment) {

            // If we are not ignoring, we MUST test if we should.
            if ($exclude == 0) {
                foreach ($excludepattern as $exp) {
                    if (preg_match($exp, $fragment)) {
                        $exclude = $exclude + 1;
                        break;
                    }
                }
            }

            if ($exclude > 0) {
                $exclude -= 1;
                $resulthtml .= $fragment;
                continue;
            } if ($skip > 0) {
                $skip -= 1;
                continue;
            } else if (strpos($fragment, '<a') !== false) {
                // This is the meat of the code - this is run every time.
                // This code only runs for fragments that are not ignored (including the tags themselves).

                $pattern = $CFG->wwwroot."/mod/helixmedia/launch.php";
                $pp = strpos($fragment, $pattern);
                $patternplace = "{{{medial_launch_base}}}/mod/helixmedia/launch.php";
                $ppp = strpos($fragment, $patternplace);
                // Forums mangle the {{{ and }}} 
                if ($ppp === false) {
                    $patternplace = "%7B%7B%7Bmedial_launch_base%7D%7D%7D/mod/helixmedia/launch.php";
                    $pppp = strpos($fragment, $patternplace);
                }

                if ($pp !== false || $ppp !== false || $pppp != false) {
                    $lp = strpos($fragment, "&amp;l=");
                    $ep = strpos($fragment, "\"", $lp);
                    if ($pp) {
                        $url = substr($fragment, $pp, $ep - $pp);
                    } else {
                        if ($ppp) {
                            $url = substr($fragment, $ppp, $ep - $ppp);
                            $url = str_replace('{{{medial_launch_base}}}', $CFG->wwwroot, $url);
                        } else {
                            $url = substr($fragment, $pppp, $ep - $ppp);
                            $url = str_replace('%7B%7B%7Bmedial_launch_base%7D%7D%7D', $CFG->wwwroot, $url);
                        }
                    }
                    $lid = substr($fragment, $lp + 7, $ep - ($lp + 7));

                    $types = strpos($fragment, "type=");
                    $type = strpos($fragment, "&", $types + 5);
                    $type = substr($fragment, $types + 5, $type - ($types + 5));

                    // Find the embed type if there is one
                    $ets = strpos($fragment, "medialembed=");
                    if ($ets !== false) {
                        $ete = strpos($fragment, "&", $ets+12);
                        $embedtype = substr($fragment, $ets + 12, $ete - ($ets + 12));
                    } else {
                        $embedtype = "iframe";
                    }

                    // Find the audioonly attribute if there is one
                    $aos = strpos($fragment, "audioonly=");
                    if ($aos !== false) {
                        $aoe = strpos($fragment, "&", $aos+10);
                        $audioonly = substr($fragment, $aos + 10, $aoe - ($aos + 10));
                    } else {
                        $audioonly = 0;
                    }

                    $output = $PAGE->get_renderer('mod_helixmedia');
                    switch ($embedtype) {
                        case "iframe":
                            $disp = new \mod_helixmedia\output\view($url, $audioonly);
                            $fragment = $output->render($disp);
                            $skip = 2;
                            break;
                        case "link":
                            $params = array('type' => $type, 'l' => $lid);
                            $disp = new \mod_helixmedia\output\modal($lid, array(), $params, false,
                                $processing[$idx+1], false, false);
                            $fragment = $output->render($disp);
                            $skip = 2;
                            break;
                        case 'thumbnail':
                            $thumbparams = array('type' => HML_LAUNCH_THUMBNAILS, 'l' => $lid);
                            $params = array('type' => $type, 'l' => $lid);
                            $disp = new \mod_helixmedia\output\modal($lid, $thumbparams, $params, "magnifier",
                                $processing[$idx+1], false, false);
                            $fragment = $output->render($disp);
                            $skip = 2;
                            break;
                        case 'library':
                            $params = array('type' => $type, 'l' => $lid);
                            $disp = new \mod_helixmedia\output\modal($lid, array(), $params, false,
                                $processing[$idx+1], false, false, 'row', false, true);
                            $fragment = $output->render($disp);
                            $skip = 2;
                            break;
                        default:
                            if ($ppp) {
                                $fragment = "<a href='".$url."' target='_blank'>";
                            }
                            break;
                    }
                }
            } else if (strpos($fragment, '<iframe') !== false) {
                $patternplace = "{{{medial_launch_base}}}/mod/helixmedia/launch.php";
                $ppp = strpos($fragment, $patternplace);
                if ($ppp) {
                    $fragment = str_replace('{{{medial_launch_base}}}', $CFG->wwwroot, $fragment);
                }
            }
            $resulthtml .= $fragment;
        }
        return $resulthtml;
    }
}
