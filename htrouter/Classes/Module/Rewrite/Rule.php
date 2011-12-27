<?php

namespace HTRouter\Module\Rewrite;

use HTRouter\Module\Rewrite\Condition;
use HTRouter\Module\Rewrite\Flag;


class Rule {
    const TYPE_PATTERN_UNKNOWN = 0;

    const TYPE_SUB_UNKNOWN   = 0;
    const TYPE_SUB           = 1;
//    const TYPE_SUB_FILE_PATH = 1;
//    const TYPE_SUB_URL_PATH  = 2;
//    const TYPE_SUB_ABS_URL   = 3;
    const TYPE_SUB_NONE      = 4;

    protected $_match = null;                // True is rule matches, false otherwise.

    protected $_conditions = array();        // All rewrite conditions in order

    protected $_request;

    function __construct(\HTRouter\Request $request, $pattern, $substitution, $flags) {
        $this->_request = $request;

        // Set default values
        $this->_pattern = $pattern;
        $this->_patternNegate = false;

        $this->_substitution = $substitution;
        $this->_substitutionType = self::TYPE_SUB_UNKNOWN;

        $this->_flags = array();

        $this->_parsePattern($pattern);
        $this->_parseSubstitution($substitution);
        $this->_parseFlags($flags);
    }

    function getRequest() {
        return $this->_request;
    }

    function __toString() {
        $ret = $this->_pattern." ".$this->_substitution." ";
        if (count($this->_flags)) $ret .= " [".join(", ", $this->_flags)."]";
        return $ret;
    }

    /**
     * Add a new condition to the list
     *
     * @param Condition $condition
     */
    public function addCondition(Condition $condition) {
        // We need this, since it's possible we need to do a back-reference to the rule from inside a condition
        $condition->linkRule($this);

        // Add condition
        $this->_conditions[] = $condition;
    }

    public function getCondititions() {
        return $this->_conditions;
    }

    /**
     * Returns true if the rule matches, false otherwise. We don't mind non-deterministic conditions like TIME_*
     *
     * @return bool
     */
    public function matches() {
        if ($this->_match == null) {
            // Cache it
            $this->_match = $this->_checkMatch();
        }

        return $this->_match;
    }

    protected function _parsePattern($pattern) {
        if ($pattern[0] == "!") {
            $this->_patternNegate = true;
            $this->_pattern = substr($pattern, 1);
        } else {
            $this->_pattern = $pattern;
        }
    }

    protected function _parseSubstitution($substitution) {
        if ($substitution == "-") {
            $this->_substitutionType = self::TYPE_SUB_NONE;
            $this->_substitution = $substitution;
        } else {
            $this->_substitutionType = self::TYPE_SUB;
            $this->_substitution = $substitution;
        }
    }

    protected function _parseFlags($flags) {
        if (empty($flags)) return;

        // Check for brackets
        if ($flags[0] != '[' && $flags[strlen($flags)-1] != ']') {
            throw new \UnexpectedValueException("Flags must be bracketed");
        }

        // Remove brackets
        $flags = substr($flags, 1, -1);

        foreach (explode(",",$flags) as $flag) {
            $flag = trim($flag);
            $key = null;
            $value = null;

            // Remove value if found (ie: cookie=TEST:VALUE)
            if (strpos("=", $flag)) {
                list($flag, $value) = explode("=", $flag, 2);

                if (strpos(":", $value)) {
                    list($key, $value) = explode(":", $value, 2);
                }
            }

            switch (strtolower($flag)) {
                case "b" :
                    $this->_flags[] = new Flag(Flag::TYPE_BEFORE, $key, $value);
                    break;
                case "chain" :
                case "c" :
                    $this->_flags[] = new Flag(Flag::TYPE_CHAIN, $key, $value);
                    break;
                case "cookie" :
                case "co" :
                    $this->_flags[] = new Flag(Flag::TYPE_COOKIE, $key, $value);
                    break;
                case "discardpath" :
                case "dpi" :
                    $this->_flags[] = new Flag(Flag::TYPE_DISCARDPATH, $key, $value);
                    break;
                case "env" :
                case "e" :
                    $this->_flags[] = new Flag(Flag::TYPE_ENV, $key, $value);
                    break;
                case "forbidden" :
                case "f" :
                    $this->_flags[] = new Flag(Flag::TYPE_FORBIDDEN, $key, $value);
                    break;
                case "gone" :
                case "g" :
                    $this->_flags[] = new Flag(Flag::TYPE_GONE, $key, $value);
                    break;
                case "handler" :
                case "h" :
                    $this->_flags[] = new Flag(Flag::TYPE_HANDLER, $key, $value);
                    break;
                case "last" :
                case "l" :
                    $this->_flags[] = new Flag(Flag::TYPE_LAST, $key, $value);
                    break;
                case "next" :
                case "n" :
                    $this->_flags[] = new Flag(Flag::TYPE_NEXT, $key, $value);
                    break;
                case "nocase" :
                case "nc" :
                    $this->_flags[] = new Flag(Flag::TYPE_NOCASE, $key, $value);
                    break;
                case "noescape" :
                case "ne" :
                    $this->_flags[] = new Flag(Flag::TYPE_NOESCAPE, $key, $value);
                    break;
                case "nosubreqs" :
                case "ns" :
                    $this->_flags[] = new Flag(Flag::TYPE_NOSUBREQS, $key, $value);
                    break;
                case "proxy" :
                case "p" :
                    $this->_flags[] = new Flag(Flag::TYPE_PROXY, $key, $value);
                    break;
                case "passthrough" :
                case "pt" :
                    $this->_flags[] = new Flag(Flag::TYPE_PASSTHROUGH, $key, $value);
                    break;
                case "qsappend" :
                case "qsa" :
                    $this->_flags[] = new Flag(Flag::TYPE_QSA, $key, $value);
                    break;
                case "redirect" :
                case "r" :
                    $this->_flags[] = new Flag(Flag::TYPE_REDIRECT, $key, $value);
                    break;
                case "skip" :
                case "s" :
                    $this->_flags[] = new Flag(Flag::TYPE_SKIP, $key, $value);
                    break;
                case "type" :
                case "t" :
                    $this->_flags[] = new Flag(Flag::TYPE_MIMETYPE, $key, $value);
                    break;
                default :
                    throw new \UnexpectedValueException("Unknown flag found in rewriterule");
                    break;
            }

        }
    }

    function hasFlag($type) {
        return ($this->getFlag($type) != null);
    }

    function getFlag($type) {
        foreach ($this->_flags as $flag) {
            if ($flag->getType() == $type) {
                return $flag;
            }
        }
        return null;
    }

    protected function _checkMatch() {
        // Returns true if the rule match, false otherwise
        $match = true;

        // First, check conditions
        foreach ($this->getCondititions() as $condition) {
            // Check if condition matches
            $match = $condition->matches();

            // Check if we need to AND or OR
            if (! $match && ! $condition->hasFlag(Flag::TYPE_ORNEXT)) {
                // Condition needs to be AND'ed, so it cannot match
                print "AND: Skipping rest of conditions!<br>";
                $match = false;
                break;
            }

            if ($match && $condition->hasFlag(Flag::TYPE_ORNEXT)) {
                // condition needs to be OR'ed and we have already a match, no need to continue
                print "OR: Skipping rest of conditions!<br>";
                $match = true;
                break;
            }
        }

        print "Conditions for rule <b>".$this."</b> match: ".($match?"yes":"no")."<br>";
        return $match;
    }


    function rewrite($url_path) {
        $utils = new \HTRouter\Utils();

        // Check if pattern matches
        $regex = "/".$this->_pattern."/";
        $match = preg_match($regex, $url_path);
        if ($this->_patternNegate) {
            $match = ! $match;
        }

        // We didn't match the pattern (or negative pattern). Return unmodified url_path
        if (! $match) {
            return $url_path;
        }


        if ($this->_substitutionType == self::TYPE_SUB_NONE) {
            // This is a dash, so no need to rewrite
            return $url_path;
        }

        if ($this->_substitutionType == self::TYPE_SUB) {
            $src_url = parse_url($url_path);
            $dst_url = parse_url($this->_substitution);
            if (! isset($src_url['host'])) $src_url['host'] = "";
            if (! isset($dst_url['host'])) $dst_url['host'] = "";

            // If it's the same host or redirect flag is on, we do a redirect
            if ($dst_url['host'] != $src_url['host'] || $this->hasFlag(Flag::TYPE_REDIRECT)) {
                // @TODO: We must do a redirect here
                $url = $utils->unparse_url($dst_url);
                $this->getRequest()->getRouter()->createRedirect(302, "Moved permanently", $url);
                exit;
            }

            // Change url_path
            return $dst_url['path'];
        }

    }
}