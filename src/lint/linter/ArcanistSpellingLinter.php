<?php

/**
 * Enforces basic spelling. Spelling inside code is actually pretty hard to
 * get right without false positives. I take a conservative approach and
 * just use a blacklisted set of words that are commonly spelled
 * incorrectly.
 */
final class ArcanistSpellingLinter extends ArcanistLinter {

  const LINT_SPELLING_PICKY = 0;
  const LINT_SPELLING_IMPORTANT = 1;

  private $partialWordRules;
  private $wholeWordRules;
  private $severity;

  public function getInfoName() {
    return pht('Spellchecker');
  }

  public function getInfoDescription() {
    return pht('Detects common misspellings of English words.');
  }

  public function __construct($severity = self::LINT_SPELLING_PICKY) {
    $this->severity = $severity;
    $this->wholeWordRules = ArcanistSpellingDefaultData::getFullWordRules();
    $this->partialWordRules =
      ArcanistSpellingDefaultData::getPartialWordRules();
  }

  public function getLinterName() {
    return 'SPELL';
  }

  public function getLinterConfigurationName() {
    return 'spelling';
  }

  public function addPartialWordRule(
    $incorrect_word,
    $correct_word,
    $severity = self::LINT_SPELLING_IMPORTANT) {

    $this->partialWordRules[$severity][$incorrect_word] = $correct_word;
  }

  public function addWholeWordRule(
    $incorrect_word,
    $correct_word,
    $severity = self::LINT_SPELLING_IMPORTANT) {

    $this->wholeWordRules[$severity][$incorrect_word] = $correct_word;
  }

  public function getLintSeverityMap() {
    return array(
      self::LINT_SPELLING_PICKY     => ArcanistLintSeverity::SEVERITY_WARNING,
      self::LINT_SPELLING_IMPORTANT => ArcanistLintSeverity::SEVERITY_ERROR,
    );
  }

  public function getLintNameMap() {
    return array(
      self::LINT_SPELLING_PICKY     => pht('Possible Spelling Mistake'),
      self::LINT_SPELLING_IMPORTANT => pht('Possible Spelling Mistake'),
    );
  }

  public function lintPath($path) {
    foreach ($this->partialWordRules as $severity => $wordlist) {
      if ($severity >= $this->severity) {
        if (!$this->isCodeEnabled($severity)) {
          continue;
        }
        foreach ($wordlist as $misspell => $correct) {
          $this->checkPartialWord($path, $misspell, $correct, $severity);
        }
      }
    }

    foreach ($this->wholeWordRules as $severity => $wordlist) {
      if ($severity >= $this->severity) {
        if (!$this->isCodeEnabled($severity)) {
          continue;
        }
        foreach ($wordlist as $misspell => $correct) {
          $this->checkWholeWord($path, $misspell, $correct, $severity);
        }
      }
    }
  }

  protected function checkPartialWord($path, $word, $correct_word, $severity) {
    $text = $this->getData($path);
    $pos = 0;
    while ($pos < strlen($text)) {
      $next = stripos($text, $word, $pos);
      if ($next === false) {
        return;
      }
      $original = substr($text, $next, strlen($word));
      $replacement = self::fixLetterCase($correct_word, $original);
      $this->raiseLintAtOffset(
        $next,
        $severity,
        pht(
          "Possible spelling error. You wrote '%s', but did you mean '%s'?",
          $word,
          $correct_word),
        $original,
        $replacement);
      $pos = $next + 1;
    }
  }

  protected function checkWholeWord($path, $word, $correct_word, $severity) {
    $text = $this->getData($path);
    $matches = array();
    $num_matches = preg_match_all(
      '#\b'.preg_quote($word, '#').'\b#i',
      $text,
      $matches,
      PREG_OFFSET_CAPTURE);
    if (!$num_matches) {
      return;
    }
    foreach ($matches[0] as $match) {
      $original = $match[0];
      $replacement = self::fixLetterCase($correct_word, $original);
      $this->raiseLintAtOffset(
        $match[1],
        $severity,
        pht(
          "Possible spelling error. You wrote '%s', but did you mean '%s'?",
          $word,
          $correct_word),
        $original,
        $replacement);
    }
  }

  public static function fixLetterCase($string, $case) {
    if ($case == strtolower($case)) {
      return strtolower($string);
    } else if ($case == strtoupper($case)) {
      return strtoupper($string);
    } else if ($case == ucwords(strtolower($case))) {
      return ucwords(strtolower($string));
    } else {
      return null;
    }
  }

}
