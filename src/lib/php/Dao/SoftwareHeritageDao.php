<?php
/*
 Copyright (C) 2019
 Author: Sandip Kumar Bhuyan<sandipbhuyan@gmail.com>

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
version 2 as published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License along
with this program; if not, write to the Free Software Foundation, Inc.,
51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

namespace Fossology\Lib\Dao;

use Fossology\Lib\Db\DbManager;
use Monolog\Logger;

/**
 * Class SoftwareHeritageDao
 * @package Fossology\Lib\Dao
 */
class SoftwareHeritageDao
{

  const SWH_STATUS_OK = 200;
  const SWH_BAD_REQUEST = 400;
  const SWH_NOT_FOUND = 404;
  const SWH_RATELIMIT_EXCEED = 429;

  /** @var DbManager */
  private $dbManager;
  /** @var Logger */
  private $logger;
  /** @var UploadDao */
  private $uploadDao;

  public function __construct(DbManager $dbManager, Logger $logger,UploadDao $uploadDao)
  {
    $this->dbManager = $dbManager;
    $this->logger = $logger;
    $this->uploadDao = $uploadDao;
  }

  /**
  * @brief Get all the pfile_fk stored in software heritage table
  * @param Integer $uploadId
  * @return array
  */
  public function getSoftwareHeritagePfileFk($uploadId)
  {
    $uploadTreeTableName = $this->uploadDao->getUploadtreeTableName($uploadId);
    $stmt = __METHOD__.$uploadTreeTableName;
    $sql = "SELECT DISTINCT(SWH.pfile_fk) FROM $uploadTreeTableName UT
              INNER JOIN software_heritage SWH ON SWH.pfile_fk = UT.pfile_fk
            WHERE UT.upload_fk = $1";
    return $this->dbManager->getRows($sql,array($uploadId),$stmt);
  }


  /**
  * @brief Store a record of Software Heritage license info in table
  * @param Integer $pfileId
  * @param String  $licenseDetails
  * @return bool
  */
  public function setSoftwareHeritageDetails($pfileId, $licenseDetails, $status)
  {
    if (!empty($this->dbManager->insertTableRow('software_heritage',['pfile_fk' => $pfileId, 'swh_shortnames' => $licenseDetails, 'swh_status' => $status]))) {
        return true;
    }
    return false;
  }

  /**
   * @brief Get a record from Software Heritage schema from the PfileId
   * @param int $pfileId
   * @return array
   */
  public function getSoftwareHetiageRecord($pfileId)
  {
    $stmt = __METHOD__ . "getSoftwareHeritageRecord";
    $row = $this->dbManager->getSingleRow(
      "SELECT swh_shortnames, swh_status FROM software_heritage WHERE pfile_fk = $1",
      array($pfileId), $stmt);
    if (empty($row)) {
      $row = [
        'swh_status' => null,
        'swh_shortnames' => null
      ];
    }
    $img = '<img alt="done" src="images/red.png" class="icon-small"/>';
    if (self::SWH_STATUS_OK == $row['swh_status']) {
      $img = '<img alt="done" src="images/green.png" class="icon-small"/>';
    }
    return ["license" => $row['swh_shortnames'], "img" => $img];
  }
}
