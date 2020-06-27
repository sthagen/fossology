<?php
/***********************************************************
 * Copyright (C) 2014-2017,2020 Siemens AG
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * version 2 as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 **********************************************************/

/**
 * @file
 * Print the founded and concluded license or copyrights as a list or CSV.
 */

use Fossology\Lib\Auth\Auth;
use Fossology\Lib\BusinessRules\ClearingDecisionFilter;
use Fossology\Lib\Dao\ClearingDao;
use Fossology\Lib\Dao\CopyrightDao;
use Fossology\Lib\Dao\LicenseDao;
use Fossology\Lib\Dao\TreeDao;
use Fossology\Lib\Dao\UploadDao;
use Fossology\Lib\Data\AgentRef;
use Fossology\Lib\Data\ClearingDecision;
use Fossology\Lib\Data\DecisionTypes;
use Fossology\Lib\Data\Tree\ItemTreeBounds;
use Fossology\Lib\Proxy\ScanJobProxy;
use Symfony\Component\HttpFoundation\Response;

/**
 * @class UIExportList
 * Print the founded and concluded license or copyrights as a list or CSV.
 */
class UIExportList extends FO_Plugin
{
  /** @var UploadDao $uploadDao
   * Upload Dao object */
  private $uploadDao;

  /** @var LicenseDao $licenseDao
   * License Dao object */
  private $licenseDao;

  /** @var ClearingDao $clearingDao
   * Clearing Dao object */
  private $clearingDao;

  /** @var CopyrightDao $copyrightDao
   * CopyrightDao object */
  private $copyrightDao;

  /** @var ClearingDecisionFilte $clearingFilter
   * Clearing filer */
  private $clearingFilter;

  /** @var TreeDao $treeDao
   * TreeDao to get file path */
  private $treeDao;

  /** @var string $delimiter
   * Delimiter for CSV */
  protected $delimiter = ',';

  /** @var string $enclosure
   * Enclosure for strings in CSV */
  protected $enclosure = '"';

  function __construct()
  {
    $this->Name = "export-list";
    $this->Title = _("Export Lists");
    $this->Dependency = array("browse");
    $this->DBaccess = PLUGIN_DB_READ;
    $this->LoginFlag = 0;
    $this->NoHeader = 0;
    parent::__construct();
    $this->uploadDao = $GLOBALS['container']->get('dao.upload');
    $this->licenseDao = $GLOBALS['container']->get('dao.license');
    $this->clearingDao = $GLOBALS['container']->get('dao.clearing');
    $this->copyrightDao = $GLOBALS['container']->get('dao.copyright');
    $this->treeDao = $GLOBALS['container']->get('dao.tree');
    $this->clearingFilter = $GLOBALS['container']->get('businessrules.clearing_decision_filter');
  }

  /**
   * Set the delimiter for CSV
   * @param string $delimiter The delimiter to be used (max len 1)
   */
  public function setDelimiter($delimiter=',')
  {
    $this->delimiter = substr($delimiter, 0, 1);
  }

  /**
   * Set the enclosure for CSV
   * @param string $enclosure The enclosure to be used (max len 1)
   */
  public function setEnclosure($enclosure='"')
  {
    $this->enclosure = substr($enclosure, 0, 1);
  }

  /**
   * \brief Customize submenus.
   */
  function RegisterMenus()
  {
    // For all other menus, permit coming back here.
    $URI = $this->Name . Traceback_parm_keep(array(
                "show",
                "format",
                "page",
                "upload",
                "item",
    ));
    $MenuDisplayString = _("Export List");
    $Item = GetParm("item", PARM_INTEGER);
    $Upload = GetParm("upload", PARM_INTEGER);
    if (empty($Item) || empty($Upload)) {
      return;
    }
    if (GetParm("mod", PARM_STRING) == $this->Name) {
      menu_insert("Browse::$MenuDisplayString", 1);
    } else {
      menu_insert("Browse::$MenuDisplayString", 1, $URI, $MenuDisplayString);
      /* bobg - This is to use a select list in the micro menu to replace the above List
        and Download, but put this select list in a form
        $LicChoices = array("Lic Download" => "Download", "Lic display" => "Display");
        $LicChoice = Array2SingleSelect($LicChoices, $SLName="LicDL");
        menu_insert("Browse::Nomos License List Download2", 1, $URI . "&output=dltext", NULL,NULL, $LicChoice);
       */
    }
  }

  /**
   * Get the agent IDs for requested agents.
   * @param integer $upload_pk  Current upload id
   * @return array  Array with agent name as key and agent id if found or false
   *                as value.
   */
  function getAgentPksFromRequest($upload_pk)
  {
    $agents = array_keys(AgentRef::AGENT_LIST);
    $agent_pks = array();

    foreach ($agents as $agent) {
      if (GetParm("agentToInclude_".$agent, PARM_STRING)) {
        /* get last nomos agent_pk that has data for this upload */
        $AgentRec = AgentARSList($agent."_ars", $upload_pk, 1);
        if ($AgentRec !== false) {
          $agent_pks[$agent] = $AgentRec[0]["agent_fk"];
        } else {
          $agent_pks[$agent] = false;
        }
      }
    }
    return $agent_pks;
  }

  /**
   * Get the list of lines for the given item.
   * @param string  $uploadtreeTablename Upload tree table for upload
   * @param integer $uploadtree_pk       Item ID
   * @param array   $agent_pks           Agents to be fetched
   * @param integer $NomostListNum       Max limit of items (-1 for unlimited)
   * @param boolean $includeSubfolder    True to include subfolders
   * @param string  $exclude             Files to be excluded
   * @param boolean $ignore              Ignore empty folders
   * @return array Array with each element containing `filePath`, list of
   *               `agentFindings` and list of `conclusions`.
   */
  public function createListOfLines($uploadtreeTablename, $uploadtree_pk,
    $agent_pks, $NomostListNum, $includeSubfolder, $exclude, $ignore)
  {
     $licensesPerFileName = array();
    /** @var ItemTreeBounds */
    $itemTreeBounds = $this->uploadDao->getItemTreeBounds($uploadtree_pk,
      $uploadtreeTablename);
    $allDecisions = $this->clearingDao->getFileClearingsFolder($itemTreeBounds,
      Auth::getGroupId());
    $editedMappedLicenses = $this->clearingFilter->filterCurrentClearingDecisionsForLicenseList($allDecisions);
    $licensesPerFileName = $this->licenseDao->getLicensesPerFileNameForAgentId($itemTreeBounds,
      $agent_pks, $includeSubfolder, $exclude, $ignore, $editedMappedLicenses);
    /* how many lines of data do you want to display */
    $currentNum = 0;
    $lines = [];
    foreach ($licensesPerFileName as $fileName => $licenseNames) {
      if ($licenseNames !== false && count($licenseNames) > 0) {
        if ($NomostListNum > -1 && ++$currentNum > $NomostListNum) {
          $lines["warn"] = _("<br><b>Warning: Only the first $NomostListNum " .
            "lines are displayed.  To see the whole list, run " .
            "fo_nomos_license_list from the command line.</b><br>");
          // TODO: the following should be done using a "LIMIT" statement in the sql query
          break;
        }

        $row = array();
        $row['filePath'] = $fileName;
        $row['agentFindings'] = $licenseNames['scanResults'];
        $row['conclusions'] = null;
        if (array_key_exists('concludedResults', $licenseNames) && !empty($licenseNames['concludedResults'])) {
          $row['conclusions'] = $this->consolidateConclusions($licenseNames['concludedResults']);
          $lines[] = $row;
        } else {
          $lines[] = $row;
        }
      }
      if (!$ignore && $licenseNames === false) {
        $row = array();
        $row['filePath'] = $fileName;
        $row['agentFindings'] = null;
        $row['conclusions'] = null;
        $lines[] = $row;
      }
    }
    return $lines;
  }

  /**
   * @copydoc FO_Plugin::getTemplateName()
   * @see FO_Plugin::getTemplateName()
   */
  public function getTemplateName()
  {
    return "ui-export-list.html.twig";
  }

  /**
   * \brief This function returns the scheduler status.
   */
  function Output()
  {
    global $PG_CONN;
    global $SysConf;
    $formVars = array();
    if (!$PG_CONN) {
      echo _("NO DB connection");
    }

    if ($this->State != PLUGIN_STATE_READY) {
      return (0);
    }
    $uploadtree_pk = GetParm("item", PARM_INTEGER);
    if (empty($uploadtree_pk)) {
      return;
    }

    $upload_pk = GetParm("upload", PARM_INTEGER);
    if (empty($upload_pk)) {
      return;
    }
    if (!$this->uploadDao->isAccessible($upload_pk, Auth::getGroupId())) {
      $text = _("Permission Denied");
      return "<h2>$text</h2>";
    }
    $uploadtreeTablename = GetUploadtreeTableName($upload_pk);

    $warnings = array();
    $exportCopyright = GetParm('export_copy', PARM_STRING);
    if (!empty($exportCopyright) && $exportCopyright == "yes") {
      $exportCopyright = true;
      $copyrightType = GetParm('copyright_type', PARM_STRING);
      $formVars["export_copy"] = "1";
      if ($copyrightType == "all") {
        $formVars["copy_type_all"] = 1;
      } else {
        $formVars["copy_type_nolic"] = 1;
      }
    } else {
      $exportCopyright = false;
      $agent_pks_dict = $this->getAgentPksFromRequest($upload_pk);
      $agent_pks = array();
      foreach ($agent_pks_dict as $agent_name => $agent_pk) {
        if ($agent_pk === false) {
          $warnings[] = _("No information for agent: $agent_name");
        } else {
          $agent_pks[] = $agent_pk;
          $formVars["agentToInclude_".$agent_name] = "1";
        }
      }
    }

    // Make sure all copyrights is selected in the form be default
    if (!(array_key_exists('copy_type_all', $formVars) ||
      array_key_exists('copy_type_nolic', $formVars))) {
      $formVars["copy_type_all"] = 1;
    }

    $dltext = (GetParm("output", PARM_STRING) == 'dltext');
    $formVars["dltext"] = $dltext;

    $NomostListNum = @$SysConf['SYSCONFIG']['NomostListNum'];
    $formVars["NomostListNum"] = $NomostListNum;

    $includeSubfolder = (GetParm("doNotIncludeSubfolder", PARM_STRING) !== "yes");
    $formVars["includeSubfolder"] = $includeSubfolder;

    $ignore = (GetParm("showContainers", PARM_STRING) !== "yes");
    $formVars["showContainers"] = !$ignore;
    $exclude = GetParm("exclude", PARM_STRING);
    $formVars["exclude"] = $exclude;

    $this->vars = array_merge($this->vars, $formVars);

    if ($exportCopyright) {
      $lines = $this->getCopyrights($upload_pk, $uploadtree_pk,
        $uploadtreeTablename, $NomostListNum, $exclude, $copyrightType);
    } else {
      $lines = $this->createListOfLines($uploadtreeTablename, $uploadtree_pk,
        $agent_pks, $NomostListNum, $includeSubfolder, $exclude, $ignore);
    }

    $this->vars['warnings'] = array();
    if (array_key_exists("warn",$lines)) {
      $warnings[] = $lines["warn"];
      unset($lines["warn"]);
    }
    foreach ($warnings as $warning) {
      $this->vars['warnings'][] = "<br><b>$warning</b><br>";
    }
    if (empty($lines)) {
      $this->vars['warnings'][] = "<br /><b>Result empty</b><br />";
    }

    if ($dltext) {
      return $this->printCSV($lines, $uploadtreeTablename, $exportCopyright);
    } else {
      $this->vars['listoutput'] = $this->printLines($lines, $exportCopyright);
      return;
    }
  }

  /**
   * Get the list of copyrights
   * @param integer $uploadId            Upload ID
   * @param integer $uploadtree_pk       Item ID
   * @param integer $uploadTreeTableName Upload tree table name
   * @param integer $NomostListNum       Limit of lines to print
   * @param integer $exclude             Files to be excluded
   * @param string  $copyrightType       Which copyrights to print (`"all"` to
   *                                     print everything, `"nolic"` to print
   *                                     only files with no scanner findings and
   *                                     no license as conclusion)
   * @return array List of copyrights with `filePath` and `content`
   */
  public function getCopyrights($uploadId, $uploadtree_pk, $uploadTreeTableName,
    $NomostListNum, $exclude, $copyrightType = "all")
  {
    $agentName = "copyright";
    $scanJobProxy = new ScanJobProxy($GLOBALS['container']->get('dao.agent'),
      $uploadId);
    $scanJobProxy->createAgentStatus([$agentName]);
    $selectedScanners = $scanJobProxy->getLatestSuccessfulAgentIds();
    if (!array_key_exists($agentName, $selectedScanners)) {
      return array();
    }
    $latestAgentId = $selectedScanners[$agentName];
    $agentFilter = ' AND C.agent_fk='.$latestAgentId;

    $itemTreeBounds = $this->uploadDao->getItemTreeBounds($uploadtree_pk,
      $uploadTreeTableName);
    $extrawhere = "UT.lft BETWEEN " . $itemTreeBounds->getLeft() . " AND " .
      $itemTreeBounds->getRight();
    if (! empty($exclude)) {
      $extrawhere .= " AND UT.ufile_name NOT LIKE '%$exclude%'";
    }
    $lines = [];

    $copyrights =  $this->copyrightDao->getScannerEntries($agentName,
      $uploadTreeTableName, $uploadId, null, $extrawhere . $agentFilter);
    $this->updateCopyrightList($lines, $copyrights, $NomostListNum,
      $uploadTreeTableName, "content");

    $copyrights = $this->copyrightDao->getEditedEntries('copyright_decision',
      $uploadTreeTableName, $uploadId, [], $extrawhere);
    $this->updateCopyrightList($lines, $copyrights, $NomostListNum,
      $uploadTreeTableName, "textfinding");

    if ($copyrightType != "all") {
      $agentList = [];
      foreach (AgentRef::AGENT_LIST as $agentname => $value) {
        $AgentRec = AgentARSList($agentname."_ars", $uploadId, 1);
        if (!empty($AgentRec)) {
          $agentList[] = $AgentRec[0]["agent_fk"];
        }
      }
      $this->removeCopyrightWithLicense($lines, $itemTreeBounds, $agentList,
        $exclude);
    }
    return $this->reduceCopyrightLines($lines);
  }

  /**
   * Update the list of copyrights with new list
   * @param array[in,out] $list     List of copyrights
   * @param array   $newCopyrights  List of copyrights to be included
   * @param integer $NomostListNum  Limit of copyrights
   * @param string  $uploadTreeTableName Upload tree table name
   * @param string  $key            Key of the array holding copyright
   */
  private function updateCopyrightList(&$list, $newCopyrights, $NomostListNum,
    $uploadTreeTableName, $key)
  {
    foreach ($newCopyrights as $copyright) {
      if ($NomostListNum > -1 && count($list) >= $NomostListNum) {
        $lines["warn"] = _("<br><b>Warning: Only the first $NomostListNum lines
 are displayed. To see the whole list, run fo_nomos_license_list from the
 command line.</b><br>");
        break;
      }
      $row = [];
      $row["content"] = $copyright[$key];
      $row["filePath"] = $this->treeDao->getFullPath($copyright["uploadtree_pk"],
        $uploadTreeTableName);
      $list[$row["filePath"]][] = $row;
    }
  }

  /**
   * Remove all files which either have license findings and not remove, or
   * have at least one license as conclusion
   * @param array[in,out] $lines            Lines to be filtered
   * @param ItemTreeBounds $itemTreeBounds  Item bounds
   * @param array $agentList                List of agent IDs
   * @param string $exclude                 Files to be excluded
   */
  private function removeCopyrightWithLicense(&$lines, $itemTreeBounds,
    $agentList, $exclude)
  {
    $licensesPerFileName = array();
    $allDecisions = $this->clearingDao->getFileClearingsFolder($itemTreeBounds,
      Auth::getGroupId());
    $editedMappedLicenses = $this->clearingFilter->filterCurrentClearingDecisionsForCopyrightList(
      $allDecisions);
    $licensesPerFileName = $this->licenseDao->getLicensesPerFileNameForAgentId(
      $itemTreeBounds, $agentList, true, $exclude, true, $editedMappedLicenses);
    foreach ($licensesPerFileName as $fileName => $licenseNames) {
      if ($licenseNames !== false && count($licenseNames) > 0) {
        if (array_key_exists('concludedResults', $licenseNames)) {
          $conclusions = $this->consolidateConclusions($licenseNames['concludedResults']);
          if (in_array("Void", $conclusions)) {
            // File has all licenses removed or irrelevant decision
            continue;
          }
          // File has license conclusions
          $this->removeIfKeyExists($lines, $fileName);
        }
        if ((! empty($licenseNames['scanResults'])) &&
          ! (in_array("No_license_found", $licenseNames['scanResults']) ||
          in_array("Void", $licenseNames['scanResults']))) {
          $this->removeIfKeyExists($lines, $fileName);
        }
      }
    }
  }

  /**
   * Reduce the 2D list of conclusions on a file to a linear array
   * @param array $conclusions 2D array of conclusions
   * @return array List of unique conclusions on the file
   */
  private function consolidateConclusions($conclusions)
  {
    $consolidatedConclusions = array();
    foreach ($conclusions as $conclusion) {
      $consolidatedConclusions = array_merge($consolidatedConclusions,
        $conclusion);
    }
    return array_unique($consolidatedConclusions);
  }

  /**
   * Remove key from a list if it exists
   *
   * @note Uses strpos to find the key
   * @param array[in,out] $lines Array
   * @param string $key          Key to be removed
   */
  private function removeIfKeyExists(&$lines, $key)
  {
    foreach (array_keys($lines) as $file) {
      if (strpos($file, $key) !== false) {
        unset($lines[$file]);
        break;
      }
    }
  }

  /**
   * Print the lines for browser
   * @param array   $lines     Lines to be printed
   * @param boolean $copyright Results are copyright?
   * @return string
   */
  private function printLines($lines, $copyright=false)
  {
    $V = '';
    if ($copyright) {
      foreach ($lines as $row) {
        $V .= $row['filePath'] . ": " . htmlentities($row['content']) . "\n";
      }
    } else {
      foreach ($lines as $row) {
        $V .= $row['filePath'];
        if ($row['agentFindings'] !== null) {
          $V .= ": " . implode(' ', $row['agentFindings']);
          if ($row['conclusions'] !== null) {
            $V .= ", " . implode(' ', $row['conclusions']);
          }
        }
        $V .= "\n";
      }
    }
    return $V;
  }

  /**
   * Print the lines as CSV
   * @param array   $lines     Lines to be printed
   * @param string  $uploadtreeTablename Upload tree table name
   * @param boolean $copyright Results are copyright?
   * @return Response CSV file as a response
   */
  private function printCSV($lines, $uploadtreeTablename, $copyright = false)
  {
    $request = $this->getRequest();
    $itemId = intval($request->get('item'));
    $path = Dir2Path($itemId, $uploadtreeTablename);
    $fileName = $path[count($path) - 1]['ufile_name']."-".date("Ymd");
    if ($copyright) {
      $fileName .= "-copyrights";
    } else {
      $fileName .= "-licenses";
    }

    $out = fopen('php://output', 'w');
    ob_start();
    if (!$copyright) {
      $head = array('file path', 'scan results', 'concluded results');
    } else {
      $head = array('file path', 'copyright');
    }
    fputcsv($out, $head, $this->delimiter, $this->enclosure);
    foreach ($lines as $row) {
      $newRow = array();
      $newRow[] = $row['filePath'];
      if ($copyright) {
        $newRow[] = $row['content'];
      } else {
        if ($row['agentFindings'] !== null) {
          $newRow[] = implode(' ', $row['agentFindings']);
        } else {
          $newRow[] = "";
        }
        if ($row['conclusions'] !== null) {
          $newRow[] = implode(' ', $row['conclusions']);
        } else {
          $newRow[] = "";
        }
      }
      fputcsv($out, $newRow, $this->delimiter, $this->enclosure);
    }
    $content = ob_get_contents();
    ob_end_clean();

    $headers = array(
      'Content-type' => 'text/csv, charset=UTF-8',
      'Content-Disposition' => 'attachment; filename='.$fileName.'.csv',
      'Pragma' => 'no-cache',
      'Cache-Control' => 'no-cache, must-revalidate, maxage=1, post-check=0, pre-check=0',
      'Expires' => 'Expires: Thu, 19 Nov 1981 08:52:00 GMT'
    );

    return new Response($content, Response::HTTP_OK, $headers);
  }

  /**
   * Reduce multidimentional copyright list to simple 2D array
   * @param array $lines Copyright list
   * @return array Simple 2D array
   */
  private function reduceCopyrightLines($lines)
  {
    $reducedLines = array();
    foreach ($lines as $line) {
      foreach ($line as $copyright) {
        $reducedLines[] = $copyright;
      }
    }
    return $reducedLines;
  }
}

$NewPlugin = new UIExportList();
$NewPlugin->Initialize();
