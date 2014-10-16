<?php
/***********************************************************
 * Copyright (C) 2014 Siemens AG
 * Author: Johannes Najjar
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
 ***********************************************************/

namespace Fossology\Lib\Util;

use Fossology\Lib\Dao\LicenseDao;
use Fossology\Lib\Dao\UploadDao;
use Fossology\Lib\Data\DecisionTypes;
use Fossology\Lib\Data\LicenseRef;

class ChangeLicenseUtility extends Object
{
  /** @var UploadDao $uploadDao */
  private $uploadDao;
  /** @var LicenseDao $licenseDao */
  private $licenseDao;

  /**
   * @param UploadDao $uploadDao
   * @param LicenseDao $licenseDao
   */

  function __construct(UploadDao $uploadDao, LicenseDao $licenseDao)
  {
    $this->uploadDao = $uploadDao;
    $this->licenseDao = $licenseDao;
  }

  /**
   * @param $uploadTreeId
   * @return array
   */
  public function createChangeLicenseForm() {
    $licenseRefArray = $this->licenseDao->getLicenseRefs();
    $listElementName = "licenseLeft";
    $output = "<select name=\"$listElementName\" id=\"$listElementName\" style=\"min-width:200px\" >\n";
    foreach ($licenseRefArray as $licenseRef)
    {
      $uri = Traceback_uri() . "?mod=popup-license" . "&lic=" . urlencode($licenseRef->getShortName());
      $title = _("License Text");
      $sizeInfo = 'width=600,height=400,toolbar=no,scrollbars=yes,resizable=yes';
      $output .= '<option value="' . $licenseRef->getId() . '" title="'.$licenseRef->getFullName().'" '
                      ."ondblclick=\"javascript:window.open('$uri','$title','$sizeInfo');\" >"
                   . $licenseRef->getShortName() 
                  . "</option>\n";
    }
    $output .= "</select>";    
    $rendererVars = array('licenseLeftSelect'=>$output);
    return $rendererVars;
  }


  public function createBulkForm() {
    $rendererVars = array();
    $rendererVars['bulkUri'] = Traceback_uri() . "?mod=popup-license";
    $rendererVars['licenseArray'] = $this->licenseDao->getLicenseArray();
    return $rendererVars;
  }
}