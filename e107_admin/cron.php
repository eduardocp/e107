<?php
/*
 * e107 website system
 *
 * Copyright (C) 2008-2010 e107 Inc (e107.org)
 * Released under the terms and conditions of the
 * GNU General Public License (http://www.gnu.org/licenses/gpl.txt)
 *
 * Cron Administration
 *
 * $URL$
 * $Id$
 *
 */

/**
 *
 * @package     e107
 * @subpackage	admin
 * @version     $Id$
 *	Admin-related functions for cron (Scheduler) management
 */

require_once('../class2.php');
if (!getperms('U'))
{
	header('location:'.e_BASE.'index.php');
	exit;
}

include_lan(e_LANGUAGEDIR.e_LANGUAGE.'/admin/lan_'.e_PAGE);



class cron_admin extends e_admin_dispatcher
{

	protected $modes = array(
		'main'		=> array(
			'controller' 	=> 'cron_admin_ui',
			'path' 			=> null,
			'ui' 			=> 'cron_admin_form_ui',
			'uipath' 		=> null
		)				
	);	


	protected $adminMenu = array(
		'main/list'		=> array('caption'=> 'Manage', 'perm' => '0'),
	//	'main/create' 	=> array('caption'=> LAN_CREATE, 'perm' => '0'),
	//	'main/prefs' 	=> array('caption'=> 'Settings', 'perm' => '0'),
	//	'main/custom'	=> array('caption'=> 'Custom Page', 'perm' => '0')		
	);

	protected $adminMenuAliases = array(
		'main/edit'	=> 'main/list'				
	);	
	
	protected $menuTitle = 'Comments';
}

class cron_admin_ui extends e_admin_ui
{
		
		protected $pluginTitle	= PAGE_NAME;
		protected $pluginName	= 'core';
		protected $table		= "cron";
		protected $pid			= "cron_id";
		protected $listOrder	= 'cron_category';
		protected $perPage		= 10;
		protected $batchDelete	= TRUE;
			   	
    	protected $fields = array(
			'checkboxes'		=> array('title'=> '',				'type' => null, 			'width' =>'5%', 	'forced'=> TRUE, 'thclass'=>'center', 'class'=>'center'),
			'cron_id'			=> array('title'=> LAN_ID,			'type' => 'number',			'width' =>'5%', 	'forced'=> FALSE, 'nolist'=>TRUE),
       		'cron_category'		=> array('title'=> LAN_CATEGORY, 	'type' => 'method', 		'data' => 'str',		'width'=>'auto','readonly' => 1,	'thclass' => '', 'batch' => TRUE, 'filter'=>TRUE),
			
       		'cron_name'			=> array('title'=> "Name",			'type' => 'text',			'width' => 'auto',	'readonly' => 1),
         	
         	'cron_description'	=> array('title'=> "Description",	'type' => 'text',			'width' => '35%',	'readonly' => 1),
         	
         	'cron_function'		=> array('title'=> "Function",		'type' => 'text',			'width' => 'auto', 	'thclass' => 'left first', 'readonly' => 1), 
         	'cron_tab'			=> array('title'=> "Tab",			'type' => 'method',			'width' => 'auto'), // Display name
		 	'cron_lastrun'		=> array('title'=> "Last-run",		'type' => 'datestamp',		'data' => 'int',	'width' => 'auto', 'readonly' => 2),	
     		'cron_active' 		=> array('title'=> "Active",		'type' => 'boolean',	'data'=> 'int', 'thclass' => 'center', 'class'=>'center', 'filter' => true, 'batch' => true,	'width' => 'auto'),
			'options' 			=> array('title'=> LAN_OPTIONS,		'type' => 'method',		'data'=> null, 'noedit'=>TRUE, 'forced'=>TRUE, 'width' => '10%', 'thclass' => 'center last', 'class' => 'center')
		);
		
		
		// public function beforeCreate($new_data)
		// {
		
		// }
		private $curCrons = array();
		private $activeCrons = 0;
				
		function init()
		{
			$pref	= e107::getPref();
			$sql	= e107::getDb();
			
			if(vartrue($_POST['cron_execute']))
			{
				$executeID = key($_POST['cron_execute']);
				$this->cronExecute($executeID);
			}

			
			if (!vartrue(e107::getPref('e_cron_pwd')))
			{
				$pwd = $this->setCronPwd();
			}
			
			$sql->db_Select_gen("SELECT cron_function,cron_active FROM #cron ");
			while($row = $sql->db_Fetch(MYSQL_ASSOC))
			{
				$this->curCrons[] = $row['cron_function'];
				if($row['cron_active']==1)
				{
					$this->activeCrons++;	
				}
			}
			
			$this->lastRefresh();
			// Import Core and Plugin e_cron data 
			
			$cronDefaults['_system'] = array(
				0 => array(
					'name' 			=> 'Test Email',
					'function' 		=> 'sendEmail',
					'category'		=> 'mail',
					'description' 	=> 'Send a test email to '.$pref['siteadminemail'].'<br />Recommended to test the scheduling system.'
					),
				1 => array(
					'name' 			=> 'Mail Queue',
					'category'		=> 'mail',
					'function' 		=> 'procEmailQueue',
					'description' 	=> 'Process mail queue'),
				2 => array(
					'name' 			=> 'Mail Bounce Check',
					'category'		=> 'mail',
					'function' 		=> 'procEmailBounce',
					'description' 	=> 'Check for bounced emails',
				//	'available' 	=> vartrue($pref['mail_bounce_auto'])
				),
			);
	
			if(!$_GET['action'])
			{
				
				$this->cronImport($cronDefaults);	// import Core Crons (if missing)
				$this->cronImport(e107::getAddonConfig('e_cron'));	// Import plugin Crons
				$this->cronImportLegacy(); // Import Legacy Cron Tab Settings	
			}
														
		}
		
		

		/**
		 * Import Cron Settings into Database. 
		*/
		public function cronImport($new_cron = FALSE)
		{
			if(!$new_cron)
			{
				return;
			}
			
			foreach($new_cron as $class => $ecron)
			{
				foreach($ecron as $val)
				{
					$insert = array(
						'cron_id'			=> 0,
						'cron_name'			=> $val['name'],
						'cron_category'		=> $val['category'],
						'cron_description' 	=> $val['description'],
						'cron_function'		=> $class."::".$val['function'],
						'cron_tab'			=> '* * * * *',
						'cron_active'		=> 0,
					);	
					
					$this->cronInsert($insert);							
				}
			}	
		}
		
		
		
		/**
		 * Import Legacy e_cron_pref settings. 
		 */
		public function cronImportLegacy()
		{
			global $pref;
			
			$cronPref = e107::getPref('e_cron_pref');
			
			
			if(!is_array($cronPref))
			{
				return;
			}
									
			foreach($cronPref as $val)
			{
				$update = array(
					'cron_tab'		=> $val['tab'],
					'cron_active'	=> $val['active'],
					'cron_function' => $val['class']."::".$val['function'],
					'WHERE'			=> "cron_function = '".$val['class']."::".$val['function']."'"
				);	
				
				$this->cronUpdate($update);					
			}
			
			e107::getConfig()->remove('e_cron_pref')->save(); 
		}
		
		
		
		
		// Insert a Cron. 
		public function cronInsert($insert)
		{
			// print_a($insert);
			// return;
// 			
			$sql = e107::getDb();
			
			if(in_array($insert['cron_function'],$this->curCrons))
			{
				return;			
			}
			
			if(!$sql->db_Insert('cron',$insert))
			{
				e107::getMessage()->add("Couldn't Import Prefs", E_MESSAGE_ERROR);
			}
			else
			{
				e107::getMessage()->add("Imported: ".$insert['cron_function'], E_MESSAGE_INFO);
			}	
		}
		
		
		
		/**
		 * Update cron timing - from legacy Pref. 
		 */
		public function cronUpdate($insert)
		{
			 // print_a($insert);
			 // return;
// 			
			$sql = e107::getDb();
			
			$cron_function = $insert['cron_function'];
			unset($insert['cron_function']);
					
			if($sql->db_Update('cron',$insert)===FALSE)
			{
				e107::getMessage()->add("Couldn't Import Timing Settings", E_MESSAGE_ERROR);
			}
			else
			{			
				e107::getMessage()->add("Imported Timing Settings for: ".$cron_function, E_MESSAGE_INFO);
			}	
		}
		
		
		
		
		
		// Process _POST before saving. 
		public function beforeUpdate($new_data, $old_data, $id)
		{
			$new_data['cron_tab'] = implode(" ", $new_data['tab']);
			return $new_data;
		}
		
		
		function setCronPwd()
		{
			//global $pref;	
			$userMethods = e107::getUserSession();
			$newpwd = $userMethods->generateRandomString('*^*#.**^*');
			$newpwd = sha1($newpwd.time());

			e107::getConfig()->set('e_cron_pwd', $newpwd)->save(false);
			return true;
	
		}
		
		
		
		function lastRefresh()
		{
			$pref 	= e107::getPref();
			$mes 	= e107::getMessage();
			
			e107::getCache()->CachePageMD5 = '_';
			$lastload = e107::getCache()->retrieve('cronLastLoad', FALSE, TRUE, TRUE);
			
			$ago = (time() - $lastload);
	
			$active = ($ago < 901) ? TRUE : FALSE;
			$status = ($active) ? LAN_ENABLED : LAN_DISABLED; // "Enabled" : "Offline";
	
			$mins = floor($ago / 60);
			$secs = $ago % 60;
	
			$lastRun = ($mins) ? $mins." minutes and ".$secs." seconds ago." : $secs." seconds ago.";
	
			$lastRefresh = ($ago < 10000) ? $lastRun : 'Never';
	
			$mes->add("Status: <b>".$status."</b>", E_MESSAGE_INFO);
	
	
			$mes->add("Active Crons: <b>".$this->activeCrons."</b>", E_MESSAGE_INFO);
			$mes->add("Last cron refresh: ".$lastRefresh, E_MESSAGE_INFO);
	
			//FIXME: for Windows, the is_executable() function only checks the file
			// extensions of exe, com, bat and cmd.
			
			$actualPerm = substr(decoct(fileperms(e_BASE."cron.php")),3);
			if ($actualPerm != 755) // is_executable() is not reliable. 
			{
				$mes->add("Please CHMOD /cron.php to 755 ", E_MESSAGE_WARNING);
			}
			//elseif (!$active) - always show instructions
			{
				$setpwd_message = "Use the following Cron Command: <b style='color:black'>".$_SERVER['DOCUMENT_ROOT'].e_HTTP."cron.php ".$pref['e_cron_pwd']."</b><br />
					Using your server control panel (eg. cPanel,Plesk etc.) please create a crontab to run this command on your server every minute.";
				$mes->add($setpwd_message, E_MESSAGE_INFO);
			}
	
		}

		function cronExecute($cron_id)
		{
			$sql = e107::getDb();
			if($sql->db_Select("cron","cron_name,cron_function","cron_id = ".intval($cron_id)))
			{
				$row = $sql->db_Fetch(MYSQL_ASSOC);	
				$class_func = $row['cron_function'];
				$cron_name = $row['cron_name'];	
			}
			
			if(!$class_func)
			{
				return;
			}

			
			list($class_name, $method_name) = explode("::", $class_func);
			$mes = e107::getMessage();
			$taskName = $class_name;
			
			if ($class_name == '_system')
			{
				require_once(e_HANDLER.'cron_class.php');
			}
			else
			{
				require_once(e_PLUGIN.$class_name.'/e_cron.php');
			}
			$class_name .= '_cron';
			$status = $this->cronExecuteMethod($class_name, $method_name) ? E_MESSAGE_SUCCESS : E_MESSAGE_ERROR;
			$mes->add("Running <b>".$cron_name."</b>", $status);
		}
		
		
		
		function cronExecuteMethod($class_name, $method_name, $return = 'boolean')
		{
			$mes = e107::getMessage();
	
			if (class_exists($class_name))
			{
				$obj = new $class_name;
				if (method_exists($obj, $method_name))
				{
					$mes->add("Executing config function <b>".$class_name." : ".$method_name."()</b>", E_MESSAGE_DEBUG);
					if ($return == 'boolean')
					{
						call_user_func(array($obj, $method_name));
						return TRUE;
					}
					else
					{
						return call_user_func(array($obj, $method_name));
					}
				}
				else
				{
					$mes->add("Config function <b>".$method_name."()</b> NOT found.", E_MESSAGE_DEBUG);
				}
			}
			return FALSE;
		}		
		
}

class cron_admin_form_ui extends e_admin_form_ui
{
	
	var $min_options = array(
						"*"																						 => LAN_CRON_11,
						"0,2,4,6,8,10,12,14,16,18,20,22,24,26,28,30,32,34,36,38,40,42,44,46,48,50,52,54,56,58"	 => LAN_CRON_12,
						"0,5,10,15,20,25,30,35,40,45,50,55"														 => LAN_CRON_13,
						"0,10,20,30,40,50"																		 => LAN_CRON_14,
						"0,15,30,45"																			 => LAN_CRON_10,
						"0,30"																					 => LAN_CRON_15
		);

	var	$hour_options = array(
						"*"								 => LAN_CRON_16,
						"0,2,4,6,8,10,12,14,16,18,20,22" => LAN_CRON_17,
						"0,3,6,9,12,15,18,21"			 => LAN_CRON_18,
						"0,6,12,18"						 => LAN_CRON_19
	);
	
	
	var $cronCategories = array(					
						'backup'	=> "Backup",
						'content'	=> ADLAN_CL_3,
						'log'		=> "Logging",
						'mail'		=> ADLAN_136,				
						'notify'	=> ADLAN_149, 
						'user'		=> LAN_USER,
	);
	
	/**
	 * Render cron_tab field
	 */
	function cron_tab($curVal,$mode) 
	{ 
		if($mode == 'read')
		{
			$sep = array();
			list($min, $hour, $day, $month, $weekday) = explode(" ", $curVal);
			$text = (isset($this->min_options[$min])) ? $this->min_options[$min] : LAN_CRON_3.$min;	
			$text .= "<br />";
			$text .= (isset($this->hour_options[$hour])) ? $this->hour_options[$hour] : LAN_CRON_4.$hour;			
			$text .= "<br />";
			$text .= ($day != '*') ? LAN_CRON_5.$day : LAN_CRON_20;
			$text .= "<br />";
			$text .= ($month != '*') ? strftime("%B", mktime(00, 00, 00, $month, 1, 2000)) : LAN_CRON_21;
			$text .= "<br />";		
			$text .= ($weekday != '*') ? strftime("%A", mktime(00, 00, 00, 5, $weekday, 2000)) : LAN_CRON_22;
			return $text; 
		}
		
		if($mode == 'write')
		{
			return $this->editTab($curVal);
		}
		
		if($mode == 'filter') // Custom Filter List 
		{		
			return;
		}
		
		if($mode == 'batch')
		{
			return;
		}
	}
	
	
	function cron_category($curVal,$mode)
	{
		if($mode == 'read')
		{
			return isset($this->cronCategories[$curVal]) ? 	$this->cronCategories[$curVal] : "";
		}
		
		if($mode == 'write')
		{
			return isset($this->cronCategories[$curVal]) ? 	$this->cronCategories[$curVal] : "";	
		}
		
		if($mode == 'filter')
		{
			return $this->cronCategories;	
		}
		if($mode == 'batch')
		{
			return;
		}
	}
	
	// Override the default Options field. 
	function options($field, $value, $attributes, $id)
	{
		
		if($attributes['mode'] == 'read')
		{
			$text = $this->renderValue('options',$value,'',$id);
			$text .= $this->submit_image('cron_execute['.$id.']', 1, 'execute', 'Execute');
			return $text;
		}
	}
	
	
	function editTab($curVal)
	{
		$sep = array();
		list($sep['minute'], $sep['hour'], $sep['day'], $sep['month'], $sep['weekday']) = explode(" ", $curVal);

		foreach ($sep as $key => $value)
		{
			if ($value == "")
			{
				$sep[$key] = "*";
			}
		}

		$minute = explode(",", $sep['minute']);
		$hour = explode(",", $sep['hour']);
		$day = explode(",", $sep['day']);
		$month = explode(",", $sep['month']);
		$weekday = explode(",", $sep['weekday']);

		

		$text = "
		<select class='tbox' style='height:90px' multiple='multiple' name='tab[minute]'>
		\n";

		foreach ($this->min_options as $key => $val)
		{
			if ($sep['minute'] == $key)
			{
				$sel = "selected='selected'";
				$minute = array();
			}
			else
			{
				$sel = "";
			}
			
			$text .= "
			<option value='$key' $sel>".$val."</option>\n";
		}

		for ($i = 0; $i <= 59; $i++)
		{
			$sel = (in_array(strval($i), $minute)) ? "selected='selected'" : "";
			$text .= "
			<option value='$i' $sel>".$i."</option>\n";
		}
		
		$text .= "
		</select>
		<select class='tbox' style='height:90px' multiple='multiple' name='tab[hour]'>
		\n";

		foreach ($this->hour_options as $key => $val)
		{
			if ($sep['hour'] == $key)
			{
			$sel = "selected='selected'";
			$hour = array();
			}
			else
			{
				$sel ="";
						}
						$text .= "<option value='$key' $sel>".$val."</option>\n";
					}

					for ($i = 0; $i <= 23; $i++)
					{
						$sel = (in_array(strval($i), $hour)) ? "selected='selected'" : "";
						$diz = mktime($i, 00, 00, 1, 1, 2000);
						$text .= "<option value='$i' $sel>".$i." - ".date("g A", $diz)."</option>\n";
					}
					$text .= "</select>
				
					<select class='tbox' style='height:90px' multiple='multiple' name='tab[day]'>\n";

					$sel_day = ($day[0] == "*") ? "selected='selected'" : "";

					$text .= "<option value='*' {$sel_day}>".LAN_CRON_20."</option>\n"; // Every Day
					for ($i = 1; $i <= 31; $i++)
					{
						$sel = (in_array($i, $day)) ? "selected='selected'" : "";
						$text .= "<option value='$i' $sel>".$i."</option>\n";
					}
					$text .= "</select>
				
					<select class='tbox' style='height:90px' multiple='multiple' name='tab[month]'>\n";

					$sel_month = ($month[0] == "*") ? "selected='selected'" : "";
					$text .= "<option value='*' $sel_month>".LAN_CRON_21."</option>\n"; // Every Month

					for ($i = 1; $i <= 12; $i++)
					{
						$sel = (in_array($i, $month)) ? "selected='selected'" : "";
						$diz = mktime(00, 00, 00, $i, 1, 2000);
						$text .= "<option value='$i' $sel>".strftime("%B", $diz)."</option>\n";
					}
					$text .= "</select>
				
					<select class='tbox' style='height:90px' multiple='multiple' name='tab[weekday]'>\n";

					$sel_weekday = ($weekday[0] == "*") ? "selected='selected'" : "";
					$text .= "<option value='*' $sel_weekday>".LAN_CRON_22."</option>\n"; // Every Week Day.
				

					for ($i = 0; $i <= 6; $i++)
					{
						$sel = (in_array(strval($i), $weekday)) ? "selected='selected'" : "";
						$text .= "<option value='$i' $sel>".strftime("%A", mktime(00, 00, 00, 5, $i, 2000))."</option>\n";
					}
					$text .= "</select>
				";
			
			return $text;
		
	}
	
	
	
	
}

new cron_admin();



$e_sub_cat = 'cron';

require_once('auth.php');

e107::getAdminUI()->runPage();
$frm = e107::getForm();
// $cron = new cron();

require_once(e_ADMIN.'footer.php');
exit;
/*
class cron
{
	protected $coreCrons = array();
	protected $cronAction;
	protected $e_cron = array();

	public function __construct()
	{
		$pref = e107::getPref();
		$mes = e107::getMessage();
		$this->cronAction = e_QUERY;

		// The 'available' flag only gives the option to configure the cron if the underlying feature is enabled
		$this->coreCrons['_system'] = array(
			0 => array('name' => 'Test Email', 'function' => 'sendEmail', 'description' => 'Send a test email to '.$pref['siteadminemail'].'<br />Recommended to test the scheduling system.'),
			1 => array('name' => 'Mail Queue', 'function' => 'procEmailQueue', 'description' => 'Process mail queue'),
			2 => array('name' => 'Mail Bounce Check', 'function' => 'procEmailBounce', 'description' => 'Check for bounced emails', 'available' => vartrue($pref['mail_bounce_auto'])),
			//	1 => array('name'=>'User Purge', 'function' => 'userPurge', 'description'=>'Purge Unactivated Users'),
			//	2 => array('name'=>'User UnActivated', 'function' => 'userUnactivated', 'description'=>'Resend activation email to unactivated users.'),
			//	3 => array('name'=>'News Sticky', 'function' => 'newsPurge', 'description'=>'Remove Sticky News Items')		
			);

		if (!vartrue($pref['e_cron_pwd']))
		{
			$pwd = $this->setCronPwd();
		}

		if (isset($_POST['submit']))
		{
			$this->cronSave();
		}

		$this->lastRefresh();
		$this->cronLoad();

		if (isset($_POST['save_prefs']))
		{
			$this->cronSavePrefs();
		}

		if (isset($_POST['execute']))
		{

			$class_func = key($_POST['execute']);
			$this->cronExecute($class_func);
		}

		// Set Core Cron Options.

		// These core functions need to be put into e_BASE/cron.php  ie. news_purge()

		if ($this->cronAction == "" || $this->cronAction == "main")
		{
			$this->cronRenderPage();
		}

		if ($this->cronAction == "pref")
		{
			$this->cronRenderPrefs();
		}
	}

	function lastRefresh()
	{
		$pref = e107::getPref();
		e107::getCache()->CachePageMD5 = '_';
		$lastload = e107::getCache()->retrieve('cronLastLoad', FALSE, TRUE, TRUE);
		$mes = e107::getMessage();
		$ago = (time() - $lastload);

		$active = ($ago < 901) ? TRUE : FALSE;
		$status = ($active) ? LAN_ENABLED : LAN_DISABLED; // "Enabled" : "Offline";

		$mins = floor($ago / 60);
		$secs = $ago % 60;

		$lastRun = ($mins) ? $mins." minutes and ".$secs." seconds ago." : $secs." seconds ago.";

		$lastRefresh = ($ago < 10000) ? $lastRun : 'Never';

		$mes->add("Status: <b>".$status."</b>", E_MESSAGE_INFO);

		// print_a($pref['e_cron_pref']);	

		if ($pref['e_cron_pref']) // grab cron
		
		{
			foreach ($pref['e_cron_pref'] as $func => $cron)
			{
				if ($cron['active'] == 1)
				{
					$list[$func] = $cron;
				}
			}
		}

		$mes->add("Active Crons: <b>".count($list)."</b>", E_MESSAGE_INFO);
		$mes->add("Last cron refresh: ".$lastRefresh, E_MESSAGE_INFO);

		//FIXME: for Windows, the is_executable() function only checks the file
		// extensions of exe, com, bat and cmd.
		
		
		$actualPerms = fileperms(e_BASE."cron.php");
	
		if (!is_executable(realpath(e_BASE."cron.php")))
		{
			$mes->add("Please CHMOD /cron.php to 755 ", E_MESSAGE_WARNING);
		}
		//elseif (!$active) - always show instructions
		{
			$setpwd_message = "Use the following Cron Command: <b style='color:black'>".$_SERVER['DOCUMENT_ROOT'].e_HTTP."cron.php ".$pref['e_cron_pwd']."</b><br />
				Using your server control panel (eg. cPanel,Plesk etc.) please create a crontab to run this command on your server every minute.";
			$mes->add($setpwd_message, E_MESSAGE_INFO);
		}

	}

	function cronName($classname, $method)
	{
		$tp = e107::getParser();

		foreach ($this->e_cron as $class => $val)
		{

			if ($class == $classname)
			{
				foreach ($val as $func)
				{
					if ($func['function'] == $method)
					{
						return $tp->toHtml($func['name']);
					}
				}
			}
		}
	}

	function cronExecute($class_func)
	{
		//TODO LANs
		list($class_name, $method_name) = explode("__", $class_func);
		$mes = e107::getMessage();

		$taskName = $class_name;
		if ($class_name == '_system')
		{
			require_once(e_HANDLER.'cron_class.php');
		}
		else
		{
			require_once(e_PLUGIN.$class_name.'/e_cron.php');
		}
		$class_name .= '_cron';
		$status = $this->cronExecuteMethod($class_name, $method_name) ? E_MESSAGE_SUCCESS : E_MESSAGE_ERROR;
		$mes->add("Running <b>".$this->cronName($taskName, $method_name)."</b>", $status);

	}

	function cronSavePref()
	{
		// Store the USERID with the password.
		// This way only the one password is needed, and the user login can be looked up in e_base/cron.php

	}

	function cronSave()
	{
		global $pref;

		$mes = e107::getMessage();
		$activeCount = 0;

		foreach ($_POST['cron'] as $key => $val)
		{
			if (!$val['active'])
			{
				$val['active'] = 0;
			}
			else
			{
				$activeCount++;
			}

			$t['minute'] = implode(",", $_POST['tab'][$key]['minute']);
			$t['hour'] = implode(",", $_POST['tab'][$key]['hour']);
			$t['day'] = implode(",", $_POST['tab'][$key]['day']);
			$t['month'] = implode(",", $_POST['tab'][$key]['month']);
			$t['weekday'] = implode(",", $_POST['tab'][$key]['weekday']);

			$val['tab'] = implode(" ", $t);
			$tabs .= $val['tab']."<br />";

			list($class, $func) = explode("__", $key);

			$val['function'] = $func;
			$val['class'] = $class;
			$val['path'] = $class;

			$cron[$key] = $val;
		}

		$pref['e_cron_pref'] = $cron;

		if (!vartrue($pref['e_cron_pwd']) || varset($_POST['generate_pwd']))
		{
			$pwd = $this->setCronPwd();

			$setpwd_message = "Use the following Cron Command:<br /><b style='color:black'>".$_SERVER['DOCUMENT_ROOT'].e_HTTP."cron.php ".$pwd."</b><br />
			This cron command is unique and will not be displayed again. Please copy and paste it into your webserver cron area to be run every minute (or 15 minutes) of every day.";
			$mes->add($setpwd_message, E_MESSAGE_WARNING);
		}

		//	print_a($pref['e_cron_pref']);

		if (save_prefs())
		{
			$mes->add(LAN_SETSAVED, E_MESSAGE_SUCCESS);
			$mes->add($activeCount." Cron(s) Active", E_MESSAGE_SUCCESS);
		}
		else
		{
			$mes->add("There was a problem saving your settings.", E_MESSAGE_ERROR);
		}

	}

	function setCronPwd()
	{
		//global $pref;

		$userMethods = e107::getUserSession();
		$newpwd = $userMethods->generateRandomString('*^*#.**^*');
		$newpwd = sha1($newpwd.time());
		//$pref['e_cron_pwd'] = $newpwd;
		e107::getConfig()->set('e_cron_pwd', $newpwd)->save(false);
		return true;

	}

	// --------------------------------------------------------------------------
	function cronRenderPrefs()
	{
		//global $frm,$ns;
		$frm = e107::getForm();
		$text = "<div style='text-align:center'>
	    <form method='post' action='"
			.e_SELF."' id='linkform'>
	    <table class='adminlist'>
	    <tr>
	    <td style='width:30%'>Cron Password</td>
	    <td style='width:70%'>
	    	"
			.$frm->password('cron_password', 100)."
	    </td>
	    </tr>



	    <tr style='vertical-align:top'>
	    <td colspan='2' class='center buttons-bar'>";
		$text .= $frm->admin_button('save_prefs', LAN_SAVE, 'update');

		$text .= "</td>
	    </tr>
	    </table>
	    </form>
	    </div>";

		e107::getRender()->tablerender(LAN_PREFS, $text);

	}

	function cronLoad() //TODO Make a generic function to work with e_cron, e_sitelink, e_url etc.
	
	{
		$pref = e107::getPref();

		$core_cron = $this->coreCrons; // May need to check 'available' flag here
		$new_cron = e107::getAddonConfig('e_cron');
		$this->e_cron = array_merge($core_cron, $new_cron); 
		return;

	}

	// ----------- Grab All e_cron parameters -----------------------------------

	function cronRenderPage()
	{
		$pref = e107::getPref();
		$cronpref = $pref['e_cron_pref'];
		$ns = e107::getRender();
		$frm = e107::getForm();
		$mes = e107::getMessage();

		$e_cron = $this->e_cron;

		// ----------------------  List All Functions -----------------------------

		$text = "<div style='text-align:center'>
		   <form method='post' action='"
			.e_SELF."' id='cronform'>
		   <table class='adminlist'>
		   <colgroup span='8'>
			   	<col></col>
				<col></col>
				<col></col>
				<col></col>
				<col></col>
				<col></col>
				<col></col>
				<col></col>
			</colgroup>
		   <thead>
		   	<tr>
			   <th>"
			.LAN_CRON_1."</th>
			   <th>"
			.LAN_CRON_2."</th>
			   <th>"
			.LAN_CRON_3."</th>
			   <th>"
			.LAN_CRON_4."</th>
			   <th>"
			.LAN_CRON_5."</th>
			   <th>"
			.LAN_CRON_6."</th>
			   <th>"
			.LAN_CRON_7."</th>
			   <th>"
			.LAN_CRON_8."</th>
			   <th>Run Now</th>
			   </tr>
		   </thead>
		   <tbody>";

		foreach ($e_cron as $plug => $cfg)
		{
			foreach ($cfg as $class => $cron)
			{
				if (!isset($cron['available']) || $cron['available']) // Only display cron functions which are available
				
				{
					$c = $plug.'__'.$cron['function']; // class and function.
					$sep = array();

					list($sep['minute'], $sep['hour'], $sep['day'], $sep['month'], $sep['weekday']) = explode(" ", $cronpref[$c]['tab']);

					foreach ($sep as $key => $value)
					{
						if ($value == "")
						{
							$sep[$key] = "*";
						}
					}

					$minute = explode(",", $sep['minute']);
					$hour = explode(",", $sep['hour']);
					$day = explode(",", $sep['day']);
					$month = explode(",", $sep['month']);
					$weekday = explode(",", $sep['weekday']);

					$min_options = array(
						"*"																						 => LAN_CRON_11,
						"0,2,4,6,8,10,12,14,16,18,20,22,24,26,28,30,32,34,36,38,40,42,44,46,48,50,52,54,56,58"	 => LAN_CRON_12,
						"0,5,10,15,20,25,30,35,40,45,50,55"														 => LAN_CRON_13,
						"0,10,20,30,40,50"																		 => LAN_CRON_14,
						"0,15,30,45"																			 => LAN_CRON_10,
						"0,30"																					 => LAN_CRON_15
					);

					$hour_options = array(
						"*"								 => LAN_CRON_16,
						"0,2,4,6,8,10,12,14,16,18,20,22" => LAN_CRON_17,
						"0,3,6,9,12,15,18,21"			 => LAN_CRON_18,
						"0,6,12,18"						 => LAN_CRON_19
					);

					$text .= "<tr>
				 <td>"
						.$cron['name']."</td>
				<td>"
						.$cron['description']."</td>
				<td>
				<input type='hidden'  name='cron[$c][path]' value='".$cron['path']."' />
					<select class='tbox' style='height:70px' multiple='multiple' name='tab[$c][minute][]'>\n";

					foreach ($min_options as $key => $val)
					{
						if ($sep['minute'] == $key)
						{
							$sel = "selected='selected'";
							$minute = array();
						}
						else
						{
							$sel = "";
						}
						$text .= "<option value='$key' $sel>".$val."</option>\n";
					}

					for ($i = 0; $i <= 59; $i++)
					{
						$sel = (in_array(strval($i), $minute)) ? "selected='selected'" : "";
						$text .= "<option value='$i' $sel>".$i."</option>\n";
					}
					$text .= "</select>
				</td>
				<td>
					<select class='tbox' style='height:70px' multiple='multiple' name='tab[$c][hour][]'>
					\n";

					foreach ($hour_options as $key => $val)
					{
						if ($sep['hour'] == $key)
						{
							$sel = "selected='selected'";
							$hour = array();
						}
						else
						{
							$sel = "";
						}
						$text .= "<option value='$key' $sel>".$val."</option>\n";
					}

					for ($i = 0; $i <= 23; $i++)
					{
						$sel = (in_array(strval($i), $hour)) ? "selected='selected'" : "";
						$diz = mktime($i, 00, 00, 1, 1, 2000);
						$text .= "<option value='$i' $sel>".$i." - ".date("g A", $diz)."</option>\n";
					}
					$text .= "</select>
				</td>
				<td>
					<select class='tbox' style='height:70px' multiple='multiple' name='tab[$c][day][]'>\n";

					$sel_day = ($day[0] == "*") ? "selected='selected'" : "";

					$text .= "<option value='*' {$sel_day}>".LAN_CRON_20."</option>\n"; // Every Day
					for ($i = 1; $i <= 31; $i++)
					{
						$sel = (in_array($i, $day)) ? "selected='selected'" : "";
						$text .= "<option value='$i' $sel>".$i."</option>\n";
					}
					$text .= "</select>
				</td>
				<td>
					<select class='tbox' style='height:70px' multiple='multiple' name='tab[$c][month][]'>\n";

					$sel_month = ($month[0] == "*") ? "selected='selected'" : "";
					$text .= "<option value='*' $sel_month>".LAN_CRON_21."</option>\n"; // Every Month

					for ($i = 1; $i <= 12; $i++)
					{
						$sel = (in_array($i, $month)) ? "selected='selected'" : "";
						$diz = mktime(00, 00, 00, $i, 1, 2000);
						$text .= "<option value='$i' $sel>".strftime("%B", $diz)."</option>\n";
					}
					$text .= "</select>
				</td>
				<td>
					<select class='tbox' style='height:70px' multiple='multiple' name='tab[$c][weekday][]'>\n";

					$sel_weekday = ($weekday[0] == "*") ? "selected='selected'" : "";
					$text .= "<option value='*' $sel_weekday>".LAN_CRON_22."</option>\n"; // Every Week Day.
					$days = array(LAN_SUN, LAN_MON, LAN_TUE, LAN_WED, LAN_THU, LAN_FRI, LAN_SAT);

					for ($i = 0; $i <= 6; $i++)
					{
						$sel = (in_array(strval($i), $weekday)) ? "selected='selected'" : "";
						$text .= "<option value='$i' $sel>".$days[$i]."</option>\n";
					}
					$text .= "</select>
				</td>
					<td class='center'>";
					$checked = ($cronpref[$c]['active'] == 1) ? "checked='checked'" : "";
					$text .= "<input type='checkbox' name='cron[$c][active]' value='1' $checked />
					</td>
					
					<td class='center'>".$frm->admin_button('execute['.$c.']', 'Run Now')."</td>
				</tr>";
				}
			}
		}
		$text .= "

		   <tr >
		   <td colspan='9' class='center'>
		   <div class='center buttons-bar'>";
		//  $text .= "<input class='button' type='submit' name='submit' value='".LAN_SAVE."' />";
		$text .= $frm->admin_button('submit', LAN_SAVE, $action = 'update');
		$text .= $frm->checkbox_switch('generate_pwd', 1, '', 'Generate new cron command');
		$text .= "</div></td>
		   </tr>
		   </tbody>
		   </table>
		   </form>
		   </div>";

		$ns->tablerender(PAGE_NAME, $mes->render().$text);
	}

	function cronOptions()
	{
		$e107 = e107::getInstance();

		$var['main']['text'] = PAGE_NAME;
		$var['main']['link'] = e_SELF;
		
		 // $var['pref']['text'] = LAN_PREFS;
		 // $var['pref']['link'] = e_SELF."?pref";
		 // $var['pref']['perm'] = "N";
		
		  $action = ($this->cronAction) ? $this->cronAction : 'main';

		e_admin_menu(PAGE_NAME, $action, $var);
	}

	function cronExecuteMethod($class_name, $method_name, $return = 'boolean')
	{
		$mes = e107::getMessage();

		if (class_exists($class_name))
		{
			$obj = new $class_name;
			if (method_exists($obj, $method_name))
			{
				$mes->add("Executing config function <b>".$class_name." : ".$method_name."()</b>", E_MESSAGE_DEBUG);
				if ($return == 'boolean')
				{
					call_user_func(array($obj, $method_name));
					return TRUE;
				}
				else
				{
					return call_user_func(array($obj, $method_name));
				}
			}
			else
			{
				$mes->add("Config function <b>".$method_name."()</b> NOT found.", E_MESSAGE_DEBUG);
			}
		}
		return FALSE;
	}
}

function cron_adminmenu()
{
	global $cron;
	$cron->cronOptions();
}
  */


?>