<?php
/**
 * SPDX-License-Identifier: GPL-2.0
 * SPDX-FileCopyrightText: Copyright (c) 2021 Orange
 * Author: Bartłomiej Dróżdż <bartlomiej.drozdz@orange.com>
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
 */

use Fossology\Lib\Plugin\AgentPlugin;

/**
 * @class ResoAgentPlugin
 * @brief Create UI plugin for Reso  agent
 */
class ResoAgentPlugin extends AgentPlugin
{
  public function __construct()
  {
    $this->Name = "agent_reso";
    $this->Title =  ("REUSE.Software Analysis (forces *Ojo License Analysis*)");
    $this->AgentName = "reso";

    parent::__construct();
  }

  /**
   * @copydoc Fossology::Lib::Plugin::AgentPlugin::AgentHasResults()
   * @see Fossology::Lib::Plugin::AgentPlugin::AgentHasResults()
   */
  function AgentHasResults($uploadId=0)
  {
    return CheckARS($uploadId, $this->AgentName, "reso agent", "reso_ars");
  }

  /**
   * @copydoc Fossology\Lib\Plugin\AgentPlugin::AgentAdd()
   * @see \Fossology\Lib\Plugin\AgentPlugin::AgentAdd()
   */
  public function AgentAdd($jobId, $uploadId, &$errorMsg, $dependencies=array(), $arguments=null)
  {
    $dependencies[] = "ojo";
    if ($this->AgentHasResults($uploadId) == 1) {
      return 0;
    }

    $jobQueueId = \IsAlreadyScheduled($jobId, $this->AgentName, $uploadId);
    if ($jobQueueId != 0) {
      return $jobQueueId;
    }

    return $this->doAgentAdd($jobId, $uploadId, $errorMsg, array("agent_ojo"),$uploadId);
  }

  /**
   * Check if agent already included in the dependency list
   * @param mixed  $dependencies Array of job dependencies
   * @param string $agentName    Name of the agent to be checked for
   * @return boolean true if agent already in dependency list else false
   */
  protected function isAgentIncluded($dependencies, $agentName)
  {
    foreach ($dependencies as $dependency) {
      if ($dependency == $agentName) {
        return true;
      }
      if (is_array($dependency) && $agentName == $dependency['name']) {
        return true;
      }
    }
    return false;
  }
}

register_plugin(new ResoAgentPlugin());
