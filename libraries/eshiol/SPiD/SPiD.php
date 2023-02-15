<?php
/**
 * @package     Joomla.Libraries
 * @subpackage  eshiol.SPiD
 *
 * @version     __DEPLOY_VERSION__
 * @since       3.10
 *
 * @author      Helios Ciancio <info (at) eshiol (dot) it>
 * @link        https://www.eshiol.it
 * @copyright   Copyright (C) 2022 - 2023 Helios Ciancio. All rights reserved
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU/GPL v3
 * SPiD for  Joomla!  is  free software.  This version may have been modified
 * pursuant to the GNU General Public License, and as distributed it includes
 * or is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 */

namespace eshiol\SPiD;

defined('_JEXEC') or die;

/**
 * SPiD Authentication
 */
class SPiD extends \SPID_PHP 
{
    public function getSPIDLevel()
    {
        $authDataArray = $this->spid_auth->getAuthDataArray();
        
        if (isset($authDataArray['saml:sp:AuthnContext']))
        {
            return (int)substr($authDataArray['saml:sp:AuthnContext'], -1);
        }
        else
        {
            return false;
        }
    }
}

?>
