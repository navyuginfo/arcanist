<?php

/**
 * Show which revision or revisions are in the working copy.
 */
final class ArcanistWhichWorkflow extends ArcanistBaseWorkflow {

  public function getWorkflowName() {
    return 'which';
  }

  public function getCommandSynopses() {
    return phutil_console_format(<<<EOTEXT
      **which** [options] (svn)
      **which** [options] [__commit__] (hg, git)
EOTEXT
      );
  }

  public function getCommandHelp() {
    return phutil_console_format(<<<EOTEXT
          Supports: svn, git, hg
          Shows which repository the current working copy corresponds to,
          which commits 'arc diff' will select, and which revision is in
          the working copy (or which revisions, if more than one matches).
EOTEXT
      );
  }

  public function requiresConduit() {
    return true;
  }

  public function requiresRepositoryAPI() {
    return true;
  }

  public function requiresAuthentication() {
    return true;
  }

  public function getArguments() {
    return array(
      'any-status' => array(
        'help' => 'Show committed and abandoned revisions.',
      ),
      'base' => array(
        'param' => 'rules',
        'help'  => 'Additional rules for determining base revision.',
        'nosupport' => array(
          'svn' => 'Subversion does not use base commits.',
        ),
        'supports' => array('git', 'hg'),
      ),
      'show-base' => array(
        'help'  => 'Print base commit only and exit.',
        'nosupport' => array(
          'svn' => 'Subversion does not use base commits.',
        ),
        'supports' => array('git', 'hg'),
      ),
      'head' => array(
        'param' => 'commit',
        'help' => pht('Specify the end of the commit range to select.'),
        'nosupport' => array(
          'svn' => pht('Subversion does not support commit ranges.'),
          'hg' => pht('Mercurial does not support --head yet.'),
        ),
        'supports' => array('git'),
      ),
      '*' => 'commit',
    );
  }

  public function run() {

    $console = PhutilConsole::getConsole();

    $this->printRepositorySection();
    $console->writeOut("\n");

    $repository_api = $this->getRepositoryAPI();

    $arg_commit = $this->getArgument('commit');
    if (count($arg_commit)) {
      $this->parseBaseCommitArgument($arg_commit);
    }
    $arg = $arg_commit ? ' '.head($arg_commit) : '';

    $repository_api->setBaseCommitArgumentRules(
      $this->getArgument('base', ''));

    $supports_ranges = $repository_api->supportsCommitRanges();

    $head_commit = $this->getArgument('head');
    if ($head_commit !== null) {
      $arg .= csprintf(' --head %R', $head_commit);
      $repository_api->setHeadCommit($head_commit);
    }

    if ($supports_ranges) {
      $relative = $repository_api->getBaseCommit();

      if ($this->getArgument('show-base')) {
        echo $relative."\n";
        return 0;
      }

      $info = $repository_api->getLocalCommitInformation();
      if ($info) {
        $commits = array();
        foreach ($info as $commit) {
          $hash     = substr($commit['commit'], 0, 16);
          $summary  = $commit['summary'];

          $commits[] = "    {$hash}  {$summary}";
        }
        $commits = implode("\n", $commits);
      } else {
        $commits = '    (No commits.)';
      }

      $explanation = $repository_api->getBaseCommitExplanation();

      $relative_summary = $repository_api->getCommitSummary($relative);
      $relative = substr($relative, 0, 16);

      if ($repository_api instanceof ArcanistGitAPI) {
        $head = $this->getArgument('head', 'HEAD');
        $command = csprintf('git diff %R', "{$relative}..{$head}");
      } else if ($repository_api instanceof ArcanistMercurialAPI) {
        $command = csprintf(
          'hg diff --rev %R',
          hgsprintf('%s', $relative));
      } else {
        throw new Exception('Unknown VCS!');
      }

      echo phutil_console_wrap(
        phutil_console_format(
          "**COMMIT RANGE**\n".
          "If you run 'arc diff{$arg}', changes between the commit:\n\n"));

      echo  "    {$relative}  {$relative_summary}\n\n";

      if ($head_commit === null) {
        $will_be_sent = pht(
          '...and the current working copy state will be sent to '.
          'Differential, because %s',
          $explanation);
      } else {
        $will_be_sent = pht(
          '...and "%s" will be sent to Differential, because %s',
          $head_commit,
          $explanation);
      }

      echo phutil_console_wrap(
        "{$will_be_sent}\n\n".
        "You can see the exact changes that will be sent by running ".
        "this command:\n\n".
        "    $ {$command}\n\n".
        "These commits will be included in the diff:\n\n");

      echo $commits."\n\n\n";
    }

    $any_status = $this->getArgument('any-status');

    $query = array(
      'status' => $any_status
        ? 'status-any'
        : 'status-open',
    );

    $revisions = $repository_api->loadWorkingCopyDifferentialRevisions(
      $this->getConduit(),
      $query);

    echo phutil_console_wrap(
      phutil_console_format(
        "**MATCHING REVISIONS**\n".
        "These Differential revisions match the changes in this working ".
        "copy:\n\n"));

    if (empty($revisions)) {
      echo "    (No revisions match.)\n";
      echo "\n";
      echo phutil_console_wrap(
        phutil_console_format(
          "Since there are no revisions in Differential which match this ".
          "working copy, a new revision will be **created** if you run ".
          "'arc diff{$arg}'.\n\n"));
    } else {
      $other_author_phids = array();
      foreach ($revisions as $revision) {
        if ($revision['authorPHID'] != $this->getUserPHID()) {
          $other_author_phids[] = $revision['authorPHID'];
        }
      }

      $other_authors = array();
      if ($other_author_phids) {
        $other_authors = $this->getConduit()->callMethodSynchronous(
          'user.query',
          array(
            'phids' => $other_author_phids,
          ));
        $other_authors = ipull($other_authors, 'userName', 'phid');
      }

      foreach ($revisions as $revision) {
        $title = $revision['title'];
        $monogram = 'D'.$revision['id'];

        if ($revision['authorPHID'] != $this->getUserPHID()) {
          $author = $other_authors[$revision['authorPHID']];
          echo pht("    %s (%s) %s\n", $monogram, $author, $title);
        } else {
          echo pht("    %s %s\n", $monogram, $title);
        }

        echo '        Reason: '.$revision['why']."\n";
        echo "\n";
      }
      if (count($revisions) == 1) {
        echo phutil_console_wrap(
          phutil_console_format(
            "Since exactly one revision in Differential matches this working ".
            "copy, it will be **updated** if you run 'arc diff{$arg}'."));
      } else {
        echo phutil_console_wrap(
          "Since more than one revision in Differential matches this working ".
          "copy, you will be asked which revision you want to update if ".
          "you run 'arc diff {$arg}'.");
      }
      echo "\n\n";
    }

    return 0;
  }

  private function printRepositorySection() {
    $console = PhutilConsole::getConsole();
    $console->writeOut("**%s**\n", pht('REPOSITORY'));

    $callsign = $this->getRepositoryCallsign();

    $console->writeOut(
      "%s\n\n",
      pht(
        'To identify the repository associated with this working copy, '.
        'arc followed this process:'));

    foreach ($this->getRepositoryReasons() as $reason) {
      $reason = phutil_console_wrap($reason, 4);
      $console->writeOut("%s\n\n", $reason);
    }

    if ($callsign) {
      $console->writeOut(
        "%s\n",
        pht('This working copy is associated with the %s repository.',
        phutil_console_format('**%s**', $callsign)));
    } else {
      $console->writeOut(
        "%s\n",
        pht('This working copy is not associated with any repository.'));
    }
  }

}
