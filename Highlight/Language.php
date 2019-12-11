<?php

/* Copyright (c)
 * - 2006-2013, Ivan Sagalaev (maniacsoftwaremaniacs.org), highlight.js
 *              (original author)
 * - 2013-2019, Geert Bergman (geertscrivo.nl), highlight.php
 * - 2014       Daniel Lynge, highlight.php (contributor)
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution.
 * 3. Neither the name of "highlight.js", "highlight.php", nor the names of its
 *    contributors may be used to endorse or promote products derived from this
 *    software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

namespace Highlight;

/**
 * @todo In highlight.php 10.x, replace the @final attribute with the `final` keyword.
 *
 * @final
 * @internal
 *
 * // Backward compatibility properties
 *
 * @property \stdClass $mode (DEPRECATED) All properties traditionally inside of $mode are now available directly from this class.
 * @property bool $caseInsensitive (DEPRECATED) Due to compatibility requirements with highlight.js, use `case_insensitive` instead.
 */
class Language extends Mode
{
    private static $COMMON_KEYWORDS = array('of', 'and', 'for', 'in', 'not', 'or', 'if', 'then');

    /**
     * @var string
     */
    public $name;

    /**
     * @var \stdClass|null
     */
    private $mode = null;

    /**
     * @todo Remove in highlight.php 10.x
     *
     * @deprecated 9.16.0 This method is no longer used since all of these properties will be set through magic methods instead
     *
     * @param \stdClass|null $e
     */
    public function complete(&$e)
    {
        if (!isset($e)) {
            $e = new \stdClass();
        }

        $patch = array(
            "begin" => true,
            "end" => true,
            "lexemes" => true,
            "illegal" => true,
        );

        $def = array(
            "begin" => "",
            "beginRe" => "",
            "beginKeywords" => "",
            "excludeBegin" => "",
            "returnBegin" => "",
            "end" => "",
            "endRe" => "",
            "endSameAsBegin" => "",
            "endsParent" => "",
            "endsWithParent" => "",
            "excludeEnd" => "",
            "returnEnd" => "",
            "starts" => "",
            "terminators" => "",
            "terminatorEnd" => "",
            "lexemes" => "",
            "lexemesRe" => "",
            "illegal" => "",
            "illegalRe" => "",
            "className" => "",
            "contains" => array(),
            "keywords" => null,
            "subLanguage" => null,
            "subLanguageMode" => "",
            "compiled" => false,
            "relevance" => 1,
            "skip" => false,
        );

        foreach ($patch as $k => $v) {
            if (isset($e->$k)) {
                $e->$k = str_replace("\\/", "/", $e->$k);
                $e->$k = str_replace("/", "\\/", $e->$k);
            }
        }

        foreach ($def as $k => $v) {
            if (!isset($e->$k) && is_object($e)) {
                $e->$k = $v;
            }
        }
    }

    public function __construct($lang, $filePath)
    {
        $this->name = $lang;

        $json = file_get_contents($filePath);
        $this->mode = json_decode($json);

        $modeAsArray = json_decode($json, true);
        parent::__construct($modeAsArray);
    }

    public function __get($name)
    {
        if ($name === 'mode') {
            @trigger_error('The "mode" property will be removed in highlight.php 10.x', E_USER_DEPRECATED);

            return $this;
        }

        if ($name === 'caseInsensitive') {
            @trigger_error('Due to compatibility requirements with highlight.js, use "case_insensitive" instead.', E_USER_DEPRECATED);

            if (isset($this->mode->case_insensitive)) {
                return $this->mode->case_insensitive;
            }

            return false;
        }

        return null;
    }

    /**
     * @param string $value
     * @param bool   $global
     *
     * @return RegEx
     */
    private function langRe($value, $global = false)
    {
        return RegExUtils::langRe($value, $global, $this->case_insensitive);
    }

    private function inherit()
    {
        $result = new Mode(array());
        $objects = func_get_args();
        $parent = array_shift($objects);

        foreach ($parent as $key => $value) {
            $result->{$key} = $value;
        }

        foreach ($objects as $object) {
            foreach ($object as $key => $value) {
                $result->{$key} = $value;
            }
        }

        return $result;
    }

    private function dependencyOnParent($mode)
    {
        if (!$mode) {
            return false;
        }

        if (isset($mode->endsWithParent) && $mode->endsWithParent) {
            return $mode->endsWithParent;
        }

        return $this->dependencyOnParent(isset($mode->starts) ? $mode->starts : null);
    }

    /**
     * @param Mode $mode
     *
     * @return Mode[]
     */
    private function expandOrCloneMode($mode)
    {
        if ($mode->variants && !$mode->cachedVariants) {
            $mode->cachedVariants = array();

            foreach ($mode->variants as $variant) {
                $mode->cachedVariants[] = $this->inherit($mode, array('variants' => null), $variant);
            }
        }

        // EXPAND
        // if we have variants then essentually "replace" the mode with the variants
        // this happens in compileMode, where this function is called from
        if ($mode->cachedVariants) {
            return $mode->cachedVariants;
        }

        // CLONE
        // if we have dependencies on parents then we need a unique
        // instance of ourselves, so we can be reused with many
        // different parents without issue
        if ($this->dependencyOnParent($mode)) {
            return array($this->inherit($mode, array(
                'starts' => $mode->starts ? $this->inherit($mode->starts) : null,
            )));
        }

        // no special dependency issues, just return ourselves
        return array($mode);
    }

    /**
     * @param Mode      $mode
     * @param Mode|null $parent
     */
    private function compileMode($mode, $parent = null)
    {
        if ($mode->compiled) {
            return;
        }

        $mode->compiled = true;
        $mode->keywords = $mode->keywords ? $mode->keywords : $mode->beginKeywords;

        if ($mode->keywords) {
            $mode->keywords = $this->compileKeywords($mode->keywords, (bool) $this->case_insensitive);
        }

        $mode->lexemesRe = $this->langRe($mode->lexemes ? $mode->lexemes : "\w+", true);

        if ($parent) {
            if ($mode->beginKeywords) {
                $mode->begin = "\\b(" . implode("|", explode(" ", $mode->beginKeywords)) . ")\\b";
            }

            if (!$mode->begin) {
                $mode->begin = "\B|\b";
            }

            $mode->beginRe = $this->langRe($mode->begin);

            if ($mode->endSameAsBegin) {
                $mode->end = $mode->begin;
            }

            if (!$mode->end && !$mode->endsWithParent) {
                $mode->end = "\B|\b";
            }

            if ($mode->end) {
                $mode->endRe = $this->langRe($mode->end);
            }

            $mode->terminator_end = $mode->end;

            if ($mode->endsWithParent && $parent->terminator_end) {
                $mode->terminator_end .= ($mode->end ? "|" : "") . $parent->terminator_end;
            }
        }

        if ($mode->illegal) {
            $mode->illegalRe = $this->langRe($mode->illegal);
        }

        if ($mode->relevance === null) {
            $mode->relevance = 1;
        }

        if (!$mode->contains) {
            $mode->contains = array();
        }

        $expandedContains = array();
        foreach ($mode->contains as &$c) {
            if (is_array($c)) {
                $c = new Mode($c);
            }

            $expandedContains = array_merge($expandedContains, $this->expandOrCloneMode(
                $c === 'self' ? $mode : $c
            ));
        }
        $mode->contains = $expandedContains;

        foreach ($mode->contains as $contain) {
            $this->compileMode($contain, $mode);
        }

        if ($mode->starts) {
            if (is_array($mode->starts)) {
                $mode->starts = new Mode($mode->starts);
            }

            $this->compileMode($mode->starts, $parent);
        }

        $terminators = new Terminators($this->case_insensitive);
        $mode->terminators = $terminators->_buildModeRegex($mode);
    }

    private function compileKeywords($rawKeywords, $caseSensitive)
    {
        $compiledKeywords = array();

        if (is_string($rawKeywords)) {
            $this->splitAndCompile("keyword", $rawKeywords, $compiledKeywords, $caseSensitive);
        } else {
            foreach ($rawKeywords as $className => $rawKeyword) {
                $this->splitAndCompile($className, $rawKeyword, $compiledKeywords, $caseSensitive);
            }
        }

        return $compiledKeywords;
    }

    private function splitAndCompile($className, $str, array &$compiledKeywords, $caseSensitive)
    {
        if ($caseSensitive) {
            $str = strtolower($str);
        }

        $keywords = explode(' ', $str);

        foreach ($keywords as $keyword) {
            $pair = explode('|', $keyword);
            $providedScore = isset($pair[1]) ? $pair[1] : null;
            $compiledKeywords[$pair[0]] = array($className, $this->scoreForKeyword($pair[0], $providedScore));
        }
    }

    private function scoreForKeyword($keyword, $providedScore)
    {
        if ($providedScore) {
            return (int) $providedScore;
        }

        return $this->commonKeyword($keyword) ? 0 : 1;
    }

    private function commonKeyword($word)
    {
        return in_array(strtolower($word), self::$COMMON_KEYWORDS);
    }

    public function compile()
    {
        if ($this->compiled) {
            return;
        }

        $jr = new JsonRef();
        $jr->decodeRef($this);
        $this->compileMode($this);
    }
}
