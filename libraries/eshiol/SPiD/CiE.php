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
 * @copyright   Copyright (C) 2022 Helios Ciancio. All rights reserved
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU/GPL v3
 * SPiD for  Joomla!  is  free software.  This version may have been modified
 * pursuant to the GNU General Public License, and as distributed it includes
 * or is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 */

namespace eshiol\SPiD;

defined('_JEXEC') or die;

use Joomla\CMS\Log\Log;
use Joomla\CMS\Log\LogEntry;

/**
 * CiE Authentication
 */
class CiE extends \SPID_PHP 
{
    function __construct($production = false, $servicename = 'cieid') 
    {
		Log::add(new LogEntry(__METHOD__, Log::DEBUG, 'plg_authentication_cie'));
        
        parent::__construct($production, $servicename);
    }
    
    public function login($idp, $l = 1, $returnTo = "", $attributeIndex = null, $post = false)
    {
		Log::add(new LogEntry(__METHOD__, Log::DEBUG, 'plg_authentication_cie'));

        parent::login($idp, 1, $returnTo, $attributeIndex, $post);
    }
}
?>
