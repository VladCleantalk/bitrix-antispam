<?php
global $MESS;
IncludeModuleLangFile(__FILE__);

/**
 * CleanTalk module class
 *
 * @author 	CleanTalk team <http://cleantalk.org>
 */
 
RegisterModuleDependences('main', 'OnPageStart', 'cleantalk.antispam', 'CleantalkAntispam', 'OnPageStartHandler',1); 
class CleantalkAntispam {

    const KEYS_NUM = 12; // 12 last JS keys are valid
    
    /**
     * Show message when spam is blocked
     * @param string message
     */
    
    static function CleantalkDie($message)
    {
    	$error_tpl=file_get_contents(dirname(__FILE__)."/error.html");
		print str_replace('%ERROR_TEXT%',$message,$error_tpl);
		die();
    }
    
    static function CleantalkGetIP()
	{
		$result=Array();
		if ( function_exists( 'apache_request_headers' ) )
		{
			$headers = apache_request_headers();
		}
		else
		{
			$headers = $_SERVER;
		}
		if ( array_key_exists( 'X-Forwarded-For', $headers ) )
		{
			$the_ip=explode(",", trim($headers['X-Forwarded-For']));
			$result[] = trim($the_ip[0]);
		}
		if ( array_key_exists( 'HTTP_X_FORWARDED_FOR', $headers ))
		{
			$the_ip=explode(",", trim($headers['HTTP_X_FORWARDED_FOR']));
			$result[] = trim($the_ip[0]);
		}
		$result[] = filter_var( $_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
	
		if(isset($_GET['sfw_test_ip']))
		{
			$result[]=$_GET['sfw_test_ip'];
		}
		return $result;
	}
    
    /**
     * Send request to cleantalk API
     * @param string server url
     * @param array data to send
     * @param boolean is data have to be JSON-encoded or not
     * @return null|string NULL when request fails or string with server response
     */
    
    static function CleantalkSendRequest($url,$data,$isJSON)
    {
    	$result=null;
		if(!$isJSON)
		{
			$data=http_build_query($data);
		}
		else
		{
			$data= json_encode($data);
		}
		if (function_exists('curl_init') && function_exists('json_decode'))
		{
		
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_TIMEOUT, 5);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
			
			// receive server response ...
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			// resolve 'Expect: 100-continue' issue
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:','Connection: Close'));
			
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			
			$result = curl_exec($ch);
			curl_close($ch);
		}
		else
		{
			$opts = array(
			    'http'=>array(
			        'method'=>"POST",
			        'content'=>$data)
			);
			$context = stream_context_create($opts);
			$result = @file_get_contents($url, 0, $context);
		}
		return $result;
    }
    
    /**
     * Get all fields from array
     * @param string email variable
     * @param string message variable
     * @param array array, containing fields
     */
    
    static function CleantalkGetFields(&$email,&$message,$arr)
	{
		$is_continue=true;
		foreach($arr as $key=>$value)
		{
			if(strpos($key,'ct_checkjs')!==false)
			{
				$email=null;
				$message='';
				$is_continue=false;
			}
		}
		if($is_continue)
		{
			foreach($arr as $key=>$value)
			{
				if(!is_array($value))
				{
					if ($email === null && preg_match("/^\S+@\S+\.\S+$/", $value))
			    	{
			            $email = $value;
			        }
			        else
			        {
			        	$message.="$value\n";
			        }
				}
				else
				{
					CleantalkAntispam::CleantalkGetFields($email,$message,$value);
				}
			}
		}
	}
	
	/**
     * Checking all forms for spam
     * @return null|boolean NULL when success or FALSE when spam detected
     */
    
    public function OnPageStartHandler()
    {
    	global $APPLICATION, $USER;
    	
    	if (!is_object($USER)) $USER = new CUser;
    	$ct_status = COption::GetOptionString('cleantalk.antispam', 'status', '0');
        $ct_global= COption::GetOptionString('cleantalk.antispam', 'form_global_check', '0');
        
        $key=COption::GetOptionString( 'cleantalk.antispam', 'key', '' );
        $last_checked=COption::GetOptionString( 'cleantalk.antispam', 'last_checked', 0 );
		$last_status=COption::GetOptionString( 'cleantalk.antispam', 'is_paid', 0 );
		$new_checked=time();
		$is_sfw=COption::GetOptionString( 'cleantalk.antispam', 'form_sfw', 0 );
		$sfw_last_updated=COption::GetOptionString( 'cleantalk.antispam', 'sfw_last_updated', 0 );
		if($is_sfw==1 && time()-$sfw_last_updated>10)
		{
			global $DB;
			$data = Array(	'auth_key' => $key,
						'method_name' => '2s_blacklists_db'
			 	);
		
			$result=CleantalkAntispam::CleantalkSendRequest('https://api.cleantalk.org/2.1',$data,false);
			$result=json_decode($result, true);

			if(isset($result['data']))
			{
				$result=$result['data'];
				$query="INSERT INTO `".$wpdb->base_prefix."cleantalk_sfw` VALUES ";
				//$wpdb->query("TRUNCATE TABLE `".$wpdb->base_prefix."cleantalk_sfw`;");
				for($i=0;$i<sizeof($result);$i++)
				{
					if($i==sizeof($result)-1)
					{
						$query.="(".$result[$i][0].",".$result[$i][1].");";
					}
					else
					{
						$query.="(".$result[$i][0].",".$result[$i][1]."), ";
					}
				}
				$DB->Query($query);
			}
			include_once("cleantalk-sfw.class.php");
			$sfw = new CleanTalkSFW();
    		$sfw->send_logs();
    		COption::SetOptionString( 'cleantalk.antispam', 'sfw_last_updated', time() );
		}
		
		if($is_sfw==1 && !$USER->IsAdmin())
		{
			include_once("cleantalk-sfw.class.php");
			$is_sfw_check=true;
		   	$ip=CleantalkAntispam::CleantalkGetIP();
		   	$ip=array_unique($ip);
		   	$sfw_log=COption::GetOptionString( 'cleantalk.antispam', 'sfw_log', '' );
		   	for($i=0;$i<sizeof($ip);$i++)
			{
		    	if(isset($_COOKIE['ct_sfw_pass_key']) && $_COOKIE['ct_sfw_pass_key']==md5($ip[$i].$key))
		    	{
		    		$is_sfw_check=false;
		    		if(isset($_COOKIE['ct_sfw_passed']))
		    		{
						if($sfw_log=='')
						{
							$sfw_log=Array();
							$sfw_log[$ip[$i]]=Array();
						}
						else
						{
							$sfw_log=json_decode($sfw_log, true);
						}
			
		    			$sfw_log[$ip[$i]]['allow']++;
		    			COption::SetOptionString( 'cleantalk.antispam', 'sfw_log', json_encode($sfw_log));
		    			@setcookie ('ct_sfw_passed', '0', 1, "/");
		    		}
		    	}
		    }
	    	if($is_sfw_check)
	    	{
	    		include_once("cleantalk-sfw.class.php");
	    		$sfw = new CleanTalkSFW();
	    		$sfw->cleantalk_get_real_ip();
	    		$sfw->check_ip();
	    		if($sfw->result)
	    		{
	    			$sfw->sfw_die();
	    		}
	    	}
		}
		

    	if($key!=''&&$key!='enter key'&&$USER->IsAdmin())
    	{
    		$new_status=$last_status;
    		if($new_checked-$last_checked>86400)
    		{
    			$url = 'https://api.cleantalk.org';
	    		$dt=Array(
	    			'auth_key'=>$key,
	    			'method_name'=> 'get_account_status');
	    		$result=CleantalkAntispam::CleantalkSendRequest($url,$dt,false);
	    		if($result!==null)
	    		{
	    			$result=json_decode($result);
	    			if(isset($result->data)&&isset($result->data->paid))
	    			{
	    				$new_status=intval($result->data->paid);
	    				if($last_status!=1&&$new_status==1)
	    				{
	    					COption::SetOptionString( 'cleantalk.antispam', 'is_paid', 1 );
	    					$show_notice=1;
	    					if(LANGUAGE_ID=='ru')
	    					{
	    						$review_message = "�������� �������� �� CleanTalk? ���������� ������ �� ����! <a target='_blank' href='http://marketplace.1c-bitrix.ru/solutions/cleantalk.antispam/#rating'>�������� ����� � Bitrix.Marketplace</a>";
	    					}
	    					else
	    					{
	    						$review_mess = "Like Anti-spam by CleanTalk? Help others learn about CleanTalk! <a  target='_blank' href='http://marketplace.1c-bitrix.ru/solutions/cleantalk.antispam/#rating'>Leave a review at the Bitrix.Marketplace</a>";
	    					}
	    					CAdminNotify::Add(array(          
								'MESSAGE' => $review_mess,          
								'TAG' => 'review_notify',          
								'MODULE_ID' => 'main',          
							'ENABLE_CLOSE' => 'Y'));
	    				}
	    			}
	    		}
	    		$url = 'https://api.cleantalk.org';
	    		$dt=Array(
	    			'auth_key'=>$key,
	    			'method_name'=> 'notice_paid_till');
	    		$result=CleantalkAntispam::CleantalkSendRequest($url,$dt,false);
	    		if($result!==null)
	    		{
	    			$result=json_decode($result);
	    			if (isset($result->moderate_ip) && $result->moderate_ip == 1)
					{
						COption::SetOptionString( 'cleantalk.antispam', 'moderate_ip', 1 );
						COption::SetOptionString( 'cleantalk.antispam', 'ip_license', $result['ip_license'] );
					}
					else
					{
						COption::SetOptionString( 'cleantalk.antispam', 'moderate_ip', 0 );
						COption::SetOptionString( 'cleantalk.antispam', 'ip_license', 0 );
					}
	    		}
	    		
	    		COption::SetOptionString( 'cleantalk.antispam', 'last_checked', $new_checked );
    		}
   		
    	}
    	
    	
        
    	if (!$USER->IsAdmin()&&$ct_status == 1 && $ct_global == 1)
    	{
	    	$sender_email = null;
		    $message = '';
		    CleantalkAntispam::CleantalkGetFields($sender_email,$message,$_POST);
		    if($sender_email!==null)
		    {
		    	$arUser = array();
				$arUser["type"] = "comment";
				$arUser["sender_email"] = $sender_email;
				$arUser["sender_nickname"] = '';
				$arUser["sender_ip"] = $_SERVER['REMOTE_ADDR'];
				$arUser["message_title"] = "";
				$arUser["message_body"] = $message;
				$arUser["example_title"] = "";
				$arUser["example_body"] = "";
				$arUser["example_comments"] = "";
				
				$aResult =  CleantalkAntispam::CheckAllBefore($arUser,FALSE);
				
				if(isset($aResult) && is_array($aResult))
				{
					if($aResult['errno'] == 0)
					{
						if($aResult['allow'] == 1)
						{
							//Not spammer - just return;
							return;
						}
						else
						{
							CleantalkAntispam::CleantalkDie($aResult['ct_result_comment']);
							return false;
						}
					}
				}
		    }
		}
    }
    
    /**
     * *** Sale section ***
     */
    
    /**
     * Checking Order forms for spam
     * @param &array Comment fields to check
     * @return null|boolean NULL when success or FALSE when spam detected
     */
    
    function OnBeforeOrderAddHandler(&$arFields)
    {
    	global $APPLICATION, $USER;
		$ct_status = COption::GetOptionString('cleantalk.antispam', 'status', '0');
        $ct_order= COption::GetOptionString('cleantalk.antispam', 'form_order', '0');
        if ($ct_status == 1 && $ct_order == 1)
        {
			$sender_email = null;
	    	$message = '';
	    	foreach ($_POST as $key => $value)
		    {
		    	if(strpos($key,'ORDER_PROP_')!==false)
		    	{
			    	if ($sender_email === null && preg_match("/^\S+@\S+\.\S+$/", $value))
			    	{
			            $sender_email = $value;
			        }
			        else
			        {
			        	$message.="$value\n";
			        }
				}
		    }
		    $message.=$_POST['ORDER_DESCRIPTION'];
		    
		    $arUser = array();
			$arUser["type"] = "comment";
			$arUser["sender_email"] = $sender_email;
			$arUser["sender_nickname"] = '';
			$arUser["sender_ip"] = $_SERVER['REMOTE_ADDR'];
			$arUser["message_title"] = "";
			$arUser["message_body"] = $message;
			$arUser["example_title"] = "";
			$arUser["example_body"] = "";
			$arUser["example_comments"] = "";
			
			$aResult =  CleantalkAntispam::CheckAllBefore($arUser,FALSE);
			if(isset($aResult) && is_array($aResult))
			{
				if($aResult['errno'] == 0)
				{
					if($aResult['allow'] == 1)
					{
						//Not spammer - just return;
						return;
					}
					else
					{
						$APPLICATION->ThrowException($aResult['ct_result_comment']);
						return false;
					}
				}
			}
		}
	}
   
    /**
     * *** TreeLike comments section ***
     */
    
    /**
     * Checking treelike comment for spam
     * @param &array Comment fields to check
     * @return null|boolean NULL when success or FALSE when spam detected
     */
    function OnBeforePrmediaCommentAddHandler(&$arFields) {
        global $APPLICATION, $USER;
        $ct_status = COption::GetOptionString('cleantalk.antispam', 'status', '0');
        $ct_comment_treelike = COption::GetOptionString('cleantalk.antispam', 'form_comment_treelike', '0');
        if ($ct_status == 1 && $ct_comment_treelike == 1) {
            if($USER->IsAdmin())
                return;
            // Skip authorized user with more than 5 approved comments
            if($USER->IsAuthorized()){
                $approved_comments = CTreelikeComments::GetList(
                    array('ID' => 'ASC'),
                    array('USER_ID'=>$arFields['USER_ID'], 'ACTIVATED' => 1),
                    '',
                    TRUE    // return count(*)
                );
                if(intval($approved_comments) > 5)
                    return;
            }
            $aComment = array();
            $aComment['type'] = 'comment';
            $aComment['sender_email'] = isset($arFields['EMAIL']) ? $arFields['EMAIL'] : '';
            $aComment['sender_nickname'] = isset($arFields['AUTHOR_NAME']) ? $arFields['AUTHOR_NAME'] : '';
            $aComment['message_title'] = '';
            $aComment['message_body'] = isset($arFields['COMMENT']) ? $arFields['COMMENT'] : '';
            $aComment['example_title'] = '';
            $aComment['example_body'] = '';
            $aComment['example_comments'] = '';

	    if(COption::GetOptionString('cleantalk.antispam', 'form_send_example', '0') == 1){
        	// Find last 10 approved comments
        	$db_res = CTreelikeComments::GetList(
            	    array('DATE' => 'DESC'),
            	    array(
                	//'OBJECT_ID'=> $arFields['OBJECT_ID'],
                	'OBJECT_ID_NUMBER'=> $arFields['OBJECT_ID'], // works
                	'ACTIVATED' => 1 // works
                	),
            	    10
        	);
        	while($ar_res = $db_res->Fetch())
            	    $aComment['example_comments'] .= $ar_res['COMMENT'] . "\n\n";
	    }

            $aResult = self::CheckAllBefore($aComment, TRUE);

            if(isset($aResult) && is_array($aResult)){
                if($aResult['errno'] == 0){
                    if($aResult['allow'] == 1){
                        // Not spammer - just return;
                        return;
                    }else{
                        if($aResult['stop_queue'] == 1){
                            // Spammer and stop_queue - return false and throw
			    if (preg_match('//u', $aResult['ct_result_comment'])){
                        	    $err_str = preg_replace('/^[^\*]*?\*\*\*|\*\*\*[^\*]*?$/iu', '', $aResult['ct_result_comment']);
                        	    $err_str = preg_replace('/<[^<>]*>/iu', '', $err_str);
			    }else{
                        	    $err_str = preg_replace('/^[^\*]*?\*\*\*|\*\*\*[^\*]*?$/i', '', $aResult['ct_result_comment']);
                        	    $err_str = preg_replace('/<[^<>]*>/i', '', $err_str);
			    }
                            $APPLICATION->ThrowException($err_str);
                            return FALSE;
                        }else{
                            // Spammer and NOT stop_queue - to manual approvement
                            // ACTIVATED = 0
/*            
                            // doesn't work - TreeLike Comments uses
                            // deprecated ExecuteModuleEvent
                            // instead of ExecuteModuleEventEx
                            // $arFields are not passwd by ref
                            // (See source - $args[] = func_get_arg($i))
                            // so I cannot change 'ACTIVATED'
                            $arFields['ACTIVATED'] = 0;
                            return;
*/
			    if (preg_match('//u', $aResult['ct_result_comment'])){
                        	    $err_str = preg_replace('/^[^\*]*?\*\*\*|\*\*\*[^\*]*?$/iu', '', $aResult['ct_result_comment']);
                        	    $err_str = preg_replace('/<[^<>]*>/iu', '', $err_str);
			    }else{
                        	    $err_str = preg_replace('/^[^\*]*?\*\*\*|\*\*\*[^\*]*?$/i', '', $aResult['ct_result_comment']);
                        	    $err_str = preg_replace('/<[^<>]*>/i', '', $err_str);
			    }
                            $APPLICATION->ThrowException($err_str);
                            return FALSE;
                        }
                    }
                }
            }
        }
    }

    /**
     * *** Blog section ***
     */
    
    /**
     * Checking blog comment for spam
     * @param &array Comment fields to check
     * @return null|boolean NULL when success or FALSE when spam detected
     */
    function OnBeforeCommentAddHandler(&$arFields) {
        global $APPLICATION, $USER;
        $ct_status = COption::GetOptionString('cleantalk.antispam', 'status', '0');
        $ct_comment_blog = COption::GetOptionString('cleantalk.antispam', 'form_comment_blog', '0');
        if ($ct_status == 1 && $ct_comment_blog == 1) {
            if($USER->IsAdmin())
                return;
            // Skip authorized user with more than 5 approved comments
            if($USER->IsAuthorized()){
                $approved_comments = CBlogComment::GetList(
                    array('ID' => 'ASC'),
                    array('AUTHOR_ID'=>$arFields['AUTHOR_ID'], 'PUBLISH_STATUS' => BLOG_PUBLISH_STATUS_PUBLISH),
                    array()    // return count(*)
                );
                if(intval($approved_comments) > 5)
                    return;
            }
            $aComment = array();
            $aComment['type'] = 'comment';
            $aComment['sender_email'] = isset($arFields['AUTHOR_EMAIL']) ? $arFields['AUTHOR_EMAIL'] : '';
            $aComment['sender_nickname'] = isset($arFields['AUTHOR_NAME']) ? $arFields['AUTHOR_NAME'] : '';
            $aComment['message_title'] = '';
            $aComment['message_body'] = isset($arFields['POST_TEXT']) ? $arFields['POST_TEXT'] : '';
            $aComment['example_title'] = '';
            $aComment['example_body'] = '';
            $aComment['example_comments'] = '';
            
	    if(COption::GetOptionString('cleantalk.antispam', 'form_send_example', '0') == 1){
        	$arPost = CBlogPost::GetByID($arFields['POST_ID']);
        	if(is_array($arPost)){
                    $aComment['example_title'] = $arPost['TITLE'];
                    $aComment['example_body'] = $arPost['DETAIL_TEXT'];
                    // Find last 10 approved comments
            	    $db_res = CBlogComment::GetList(
                	array('DATE_CREATE' => 'DESC'),
                	array('POST_ID'=> $arFields['POST_ID'], 'PUBLISH_STATUS' => BLOG_PUBLISH_STATUS_PUBLISH),
                	false,
                	array('nTopCount' => 10),
                	array('POST_TEXT')
            	    );
            	    while($ar_res = $db_res->Fetch())
                	$aComment['example_comments'] .= $ar_res['TITLE'] . "\n\n" . $ar_res['POST_TEXT'] . "\n\n";
		}
    	    }

            $aResult = self::CheckAllBefore($aComment, TRUE);

            if(isset($aResult) && is_array($aResult)){
                if($aResult['errno'] == 0){
                    if($aResult['allow'] == 1){
                        // Not spammer - just return;
                        return;
                    }else{
                        if($aResult['stop_queue'] == 1){
                            // Spammer and stop_queue - return false and throw
			    if (preg_match('//u', $aResult['ct_result_comment'])){
                        	    $err_str = preg_replace('/^[^\*]*?\*\*\*|\*\*\*[^\*]*?$/iu', '', $aResult['ct_result_comment']);
                        	    $err_str = preg_replace('/<[^<>]*>/iu', '', $err_str);
			    }else{
                        	    $err_str = preg_replace('/^[^\*]*?\*\*\*|\*\*\*[^\*]*?$/i', '', $aResult['ct_result_comment']);
                        	    $err_str = preg_replace('/<[^<>]*>/i', '', $err_str);
			    }
                            $APPLICATION->ThrowException($err_str);
                            return FALSE;
                        }else{
                            // Spammer and NOT stop_queue - to manual approvement
                            // BLOG_PUBLISH_STATUS_READY
                            // It doesn't work
                            // values below results in endless 'Loading' AJAX message :(
                            //$arFields['PUBLISH_STATUS'] = BLOG_PUBLISH_STATUS_READY;
                            //$arFields['PUBLISH_STATUS'] = BLOG_PUBLISH_STATUS_DRAFT;
                            //return;

                            // It doesn't work too
                            // Status setting in OnCommentAddHandler still results in endless 'Loading' AJAX message :(
                            //$GLOBALS['ct_after_CommentAdd_status'] = BLOG_PUBLISH_STATUS_READY;
                            //return;
                            
			    if (preg_match('//u', $aResult['ct_result_comment'])){
                        	    $err_str = preg_replace('/^[^\*]*?\*\*\*|\*\*\*[^\*]*?$/iu', '', $aResult['ct_result_comment']);
                        	    $err_str = preg_replace('/<[^<>]*>/iu', '', $err_str);
			    }else{
                        	    $err_str = preg_replace('/^[^\*]*?\*\*\*|\*\*\*[^\*]*?$/i', '', $aResult['ct_result_comment']);
                        	    $err_str = preg_replace('/<[^<>]*>/i', '', $err_str);
			    }
                            $APPLICATION->ThrowException($err_str);
                            return FALSE;
                        }
                    }
                }
            }
        }
    }

    /**
     * *** Forum section ***
     */

    /**
     * Checking forum comment for spam - part 1 - checking itself
     * @param &array Comment fields to check
     * @return null|boolean NULL when success or FALSE when spam detected
     */
    function OnBeforeMessageAddHandler(&$arFields) {
        // works
        global $APPLICATION, $USER;
        $ct_status = COption::GetOptionString('cleantalk.antispam', 'status', '0');
        $ct_comment_forum = COption::GetOptionString('cleantalk.antispam', 'form_comment_forum', '0');
        if ($ct_status == 1 && $ct_comment_forum == 1) {
            if($USER->IsAdmin())
                return;
            // Skip authorized user with more than 5 approved messages
            if($USER->IsAuthorized()){
                $approved_messages = CForumMessage::GetList(
                    array('ID'=>'ASC'),
                    array('AUTHOR_ID'=>$arFields['AUTHOR_ID'], 'APPROVED'=>'Y'),
                    TRUE
                );
                if(intval($approved_messages) > 5)
                    return;
            }
            $aComment = array();
            $aComment['type'] = 'comment';
            $aComment['sender_email'] = isset($arFields['AUTHOR_EMAIL']) ? $arFields['AUTHOR_EMAIL'] : '';
            $aComment['sender_nickname'] = isset($arFields['AUTHOR_NAME']) ? $arFields['AUTHOR_NAME'] : '';
            $aComment['message_title'] = '';
            $aComment['message_body'] = isset($arFields['POST_MESSAGE']) ? $arFields['POST_MESSAGE'] : '';
            $aComment['example_title'] = '';
            $aComment['example_body'] = '';
            $aComment['example_comments'] = '';
            
	    if(COption::GetOptionString('cleantalk.antispam', 'form_send_example', '0') == 1){
        	$arTopic = CForumTopic::GetByID($arFields['TOPIC_ID']);
        	if(is_array($arTopic)){
                    $aComment['example_title'] = $arTopic['TITLE'];

            	    // Messages contains both topic bodies and comment bodies
            	    // First find topic body
            	    $db_res = CForumMessage::GetList(
                	array('ID'=>'ASC'),
                	array('TOPIC_ID'=>$arFields['TOPIC_ID'], 'NEW_TOPIC'=>'Y', 'APPROVED'=>'Y'),
                	FALSE,
                	1
            	    );
            	    $ar_res = $db_res->Fetch();
            	    if($ar_res)
                	$aComment['example_body'] = $ar_res['POST_MESSAGE'];

            	    // Second find last 10 approved comment bodies
            	    $comments = array();
            	    $db_res = CForumMessage::GetList(
                	array('POST_DATE'=>'DESC'),
                	array('TOPIC_ID'=>$arFields['TOPIC_ID'], 'NEW_TOPIC'=>'N', 'APPROVED'=>'Y'),
                	FALSE,
                	10
            	    );
            	    while($ar_res = $db_res->Fetch())
                	$aComment['example_comments'] .= $ar_res['POST_MESSAGE'] . "\n\n";
		}
            }
            
            $aResult = self::CheckAllBefore($aComment, TRUE);

            if(isset($aResult) && is_array($aResult)){
                if($aResult['errno'] == 0){
                    if($aResult['allow'] == 1){
                        // Not spammer - just return;
                        return;
                    }else{
                        if($aResult['stop_queue'] == 1){
                            // Spammer and stop_queue - return false and throw
			    if (preg_match('//u', $aResult['ct_result_comment'])){
                        	    $err_str = preg_replace('/^[^\*]*?\*\*\*|\*\*\*[^\*]*?$/iu', '', $aResult['ct_result_comment']);
                        	    $err_str = preg_replace('/<[^<>]*>/iu', '', $err_str);
			    }else{
                        	    $err_str = preg_replace('/^[^\*]*?\*\*\*|\*\*\*[^\*]*?$/i', '', $aResult['ct_result_comment']);
                        	    $err_str = preg_replace('/<[^<>]*>/i', '', $err_str);
			    }
                            $APPLICATION->ThrowException($err_str);
                            return FALSE;
                        }else{
                            // Spammer and NOT stop_queue - to manual approvement
                            // It works!
                            $arFields['APPROVED'] = 'N';
                            return;
                        }
                    }
                }
            }
        }
    }

    /**
     * Checking forum comment for spam - part 2 - stores needed data and logs event
     * @param int ID of added comment
     * @param array Comment fields
     */
    function OnAfterMessageAddHandler($id, $arFields) {
        // works
        $ct_status = COption::GetOptionString('cleantalk.antispam', 'status', '0');
        $ct_comment_forum = COption::GetOptionString('cleantalk.antispam', 'form_comment_forum', '0');
        if ($ct_status == 1 && $ct_comment_forum == 1) {
            self::CheckCommentAfter('forum', $id, GetMessage('CLEANTALK_MESSAGE') . ' ID=' . $id);
        }
    }
    
    /**
     * Sending admin's decision (show or hide comment) to CleanTalk server
     * @param int ID of added comment
     * @param string Type of action - must be 'SHOW' or 'HIDE' only
     * @param array Comment fields
     */
    function OnMessageModerateHandler( $id, $type, $arFields){
        // works
        $ct_status = COption::GetOptionString('cleantalk.antispam', 'status', '0');
        $ct_comment_forum = COption::GetOptionString('cleantalk.antispam', 'form_comment_forum', '0');
        if ($ct_status == 1 && $ct_comment_forum == 1) {
            if ($type == 'SHOW') {
                //send positive feedback
                self::SendFeedback('forum', $id, 'Y');
            }else if ($type == 'HIDE'){
                // send negative feedback
                self::SendFeedback('forum', $id, 'N');
            }
        }
    }

    /**
     * Sending admin's decision (delete comment) to CleanTalk server
     * @param int ID of added comment
     * @param array Comment fields
     */
    function OnBeforeMessageDeleteHandler($id, $arFields) {
        // works
        $ct_status = COption::GetOptionString('cleantalk.antispam', 'status', '0');
        $ct_comment_forum = COption::GetOptionString('cleantalk.antispam', 'form_comment_forum', '0');
        if ($ct_status == 1 && $ct_comment_forum == 1) {
            // send negative feedback
            self::SendFeedback('forum', $id, 'N');
        }
    }
    
    /**
     * *** User registration section ***
     */

    /**
     * Checking new user for spammer/bot
     * @param &array New user fields to check
     * @return null|boolean NULL when success or FALSE when spammer/bot detected
     */
    function OnBeforeUserRegisterHandler(&$arFields) {
        global $APPLICATION;
        
        $ct_status = COption::GetOptionString('cleantalk.antispam', 'status', '0');
        $ct_new_user = COption::GetOptionString('cleantalk.antispam', 'form_new_user', '0');

        if ($ct_status == 1 && $ct_new_user == 1) {
            $aUser = array();
            $aUser['type'] = 'register';
            $aUser['sender_email'] = isset($arFields['EMAIL']) ? $arFields['EMAIL'] : '';
            $aUser['sender_nickname'] = isset($arFields['LOGIN']) ? $arFields['LOGIN'] : '';
            
            $aResult = self::CheckAllBefore($aUser, TRUE);

            if(isset($aResult) && is_array($aResult)){
                if($aResult['errno'] == 0){
                    if($aResult['allow'] == 1){
                        // Not spammer - just return;
                        return;
                    }else{
                        // Spammer - return false and throw
                        // Note: 'stop_queue' is ignored in user checking
			if (preg_match('//u', $aResult['ct_result_comment'])){
                            $err_str = preg_replace('/^[^\*]*?\*\*\*|\*\*\*[^\*]*?$/iu', '', $aResult['ct_result_comment']);
                            $err_str = preg_replace('/<[^<>]*>/iu', '', $err_str);
			}else{
                            $err_str = preg_replace('/^[^\*]*?\*\*\*|\*\*\*[^\*]*?$/i', '', $aResult['ct_result_comment']);
                            $err_str = preg_replace('/<[^<>]*>/i', '', $err_str);
			}
                        $APPLICATION->ThrowException($err_str);
                        return false;
                    }
                }
            }
        }
    }

    /**
     * *** Common section ***
     */
    
    /**
     * CleanTalk additions to logging types
     */
    function OnEventLogGetAuditTypesHandler(){
        return array(
            'CLEANTALK_EVENT' => '[CLEANTALK_EVENT] ' . GetMessage('CLEANTALK_EVENT'),
            'CLEANTALK_E_SERVER' => '[CLEANTALK_E_SERVER] ' . GetMessage('CLEANTALK_E_SERVER'),
            'CLEANTALK_E_INTERNAL' => '[CLEANTALK_E_INTERNAL] ' . GetMessage('CLEANTALK_E_INTERNAL')
        );
    }
    
    /**
     * Content modification - adding JavaScript code to final content
     * @param string Content to modify
     */
    function OnEndBufferContentHandler(&$content) {
	if(!defined("ADMIN_SECTION") && COption::GetOptionString( 'cleantalk.antispam', 'status', 0 ) == 1){
    	    if (!session_id()) session_start();
	    $_SESSION['ct_submit_time'] = time();

	    $field_name = 'ct_checkjs';
	    $ct_check_def = '0';
	    if (!isset($_COOKIE[$field_name])) setcookie($field_name, $ct_check_def, 0, '/');

	    $ct_check_values = self::SetCheckJSValues();
	    $js_template = '<script type="text/javascript">function ctSetCookie(c_name, value, def_value){document.cookie = c_name + "=" + escape(value.replace(/^def_value$/, value)) + "; path=/";} setTimeout("ctSetCookie(\"%s\", \"%s\", \"%s\");",1000);</script>';
	    //$js_template = "eval(function(p,a,c,k,e,d){while(c--){if(k[c]){p=p.replace(new RegExp('\\b'+c+'\\b','g'),k[c])}}return p}('3 2(1,0){4.5=1+\"=\"+6(0)+\"; 7=/\"}10(\"2(\\\"9\\\", \\\"8\\\");\",11);',10,12,'value|c_name|ctSetCookie|function|document|cookie|escape|path|".$ct_check_values[0]."|ct_checkjs|setTimeout|1000'.split('|')))";
	    $ct_addon_body = sprintf($js_template, $field_name, $ct_check_values[0], $ct_check_def);
	    $content = preg_replace('/(<head[^>]*>)/i', '${1}'."\n".$ct_addon_body, $content, 1);
	}
    }

    /**
     * *** Universal methods section - for using in other modules ***
     */

    /**
     * Deprecated!
     */
    static function FormAddon($sType) {
	return '';
    }

    /**
     * Universal method for checking comment or new user for spam
     * It makes checking itself
     * Use it in your modules
     * You must call it from OnBefore* events
     * @param &array Entity to check (comment or new user)
     * @param boolean Notify admin about errors by email or not (default FALSE)
     * @return array|null Checking result or NULL when bad params
     */
    static function CheckAllBefore(&$arEntity, $bSendEmail = FALSE) {
      global $DB;
      if(!is_array($arEntity) || !array_key_exists('type', $arEntity)){
            CEventLog::Add(array(
                'SEVERITY' => 'SECURITY',
                'AUDIT_TYPE_ID' => 'CLEANTALK_E_INTERNAL',
                'MODULE_ID' => 'cleantalk.antispam',
                'DESCRIPTION' => GetMessage('CLEANTALK_E_PARAM')
            ));
            return;
      }

        $type = $arEntity['type'];
        if($type != 'comment' && $type != 'register'){
            CEventLog::Add(array(
                'SEVERITY' => 'SECURITY',
                'AUDIT_TYPE_ID' => 'CLEANTALK_E_INTERNAL',
                'MODULE_ID' => 'cleantalk.antispam',
                'DESCRIPTION' => GetMessage('CLEANTALK_E_TYPE')
            ));
            return;
        }

        require_once(dirname(__FILE__) . '/classes/general/cleantalk.class.php');

        $ct_key = COption::GetOptionString('cleantalk.antispam', 'key', '0');
        $ct_ws = self::GetWorkServer();

        $ct_submit_time = NULL;
        if(isset($_SESSION['ct_submit_time']))
            $ct_submit_time = time() - $_SESSION['ct_submit_time'];

	if (!isset($_COOKIE['ct_checkjs']))
	    $checkjs = NULL;
	elseif (in_array($_COOKIE['ct_checkjs'], self::GetCheckJSValues()))
	    $checkjs = 1;
	else
	    $checkjs = 0;

        if(isset($_SERVER['HTTP_USER_AGENT']))
            $user_agent = htmlspecialchars((string) $_SERVER['HTTP_USER_AGENT']);
        else
            $user_agent = NULL;

        if(isset($_SERVER['HTTP_REFERER']))
            $refferrer = htmlspecialchars((string) $_SERVER['HTTP_REFERER']);
        else
            $refferrer = NULL;

        $sender_info = array(
            'cms_lang' => 'ru',
            'REFFERRER' => $refferrer,
            'post_url' => $refferrer,
            'USER_AGENT' => $user_agent
        );
        $sender_info = json_encode($sender_info);

        $ct = new Cleantalk();
        $ct->work_url = $ct_ws['work_url'];
        $ct->server_url = $ct_ws['server_url'];
        $ct->server_ttl = $ct_ws['server_ttl'];
        $ct->server_changed = $ct_ws['server_changed'];

        if(defined('BX_UTF'))
            $logicalEncoding = "utf-8";
        elseif(defined("SITE_CHARSET") && (strlen(SITE_CHARSET) > 0))
            $logicalEncoding = SITE_CHARSET;
        elseif(defined("LANG_CHARSET") && (strlen(LANG_CHARSET) > 0))
            $logicalEncoding = LANG_CHARSET;
        elseif(defined("BX_DEFAULT_CHARSET"))
            $logicalEncoding = BX_DEFAULT_CHARSET;
        else
            $logicalEncoding = "windows-1251";

        $logicalEncoding = strtolower($logicalEncoding);
        $ct->data_codepage = $logicalEncoding == 'utf-8' ? NULL : $logicalEncoding;

        $ct_request = new CleantalkRequest();
        $ct_request->auth_key = $ct_key;
        $ct_request->sender_email = isset($arEntity['sender_email']) ? $arEntity['sender_email'] : '';
        $ct_request->sender_nickname = isset($arEntity['sender_nickname']) ? $arEntity['sender_nickname'] : '';
	$ct_request->sender_ip = $ct->ct_session_ip($_SERVER['REMOTE_ADDR']);
        $ct_request->agent = 'bitrix-370';
        $ct_request->response_lang = 'ru';
        $ct_request->js_on = $checkjs;
        $ct_request->sender_info = $sender_info;

        switch ($type) {
            case 'comment':
                $timelabels_key = 'mail_error_comment';
                $ct_request->submit_time = $ct_submit_time;

                $ct_request->message = isset($arEntity['message_title']) ? $arEntity['message_title'] : '';
                $ct_request->message .= "\n\n";
                $ct_request->message .= isset($arEntity['message_body']) ? $arEntity['message_body'] : '';

                $ct_request->example = isset($arEntity['example_title']) ? $arEntity['example_title'] : '';
                $ct_request->example .= empty($ct_request->example) ? '' :"\n\n";
                $ct_request->example .= isset($arEntity['example_body']) ? $arEntity['example_body'] : '';
                $ct_request->example .= empty($ct_request->example) ? '' :"\n\n";
                $ct_request->example .= isset($arEntity['example_comments']) ? $arEntity['example_comments'] : '';
		if(empty($ct_request->example))
		    $ct_request->example = NULL;

                $a_post_info['comment_type'] = 'comment';
                $post_info = json_encode($a_post_info);
                if($post_info === FALSE)
                    $post_info = '';
                $ct_request->post_info = $post_info;

                $ct_result = $ct->isAllowMessage($ct_request);
                break;
            case 'register':
                $timelabels_key = 'mail_error_reg';
                $ct_request->submit_time = $ct_submit_time;
                $ct_request->tz = isset($arEntity['user_timezone']) ? $arEntity['user_timezone'] : NULL;

                $ct_result = $ct->isAllowUser($ct_request);
        }
        
        $ret_val = array();
        $ret_val['ct_request_id'] = $ct_result->id;

        if($ct->server_change)
            self::SetWorkServer(
                $ct->work_url, $ct->server_url, $ct->server_ttl, time()
            );

        // First check errstr flag.
        if(!empty($ct_result->errstr)
            || (!empty($ct_result->inactive) && $ct_result->inactive == 1)
        ){
            // Cleantalk error so we go default way (no action at all).
            $ret_val['errno'] = 1;
            // Just inform admin.
            $err_title = 'CleanTalk module error';
            if(!empty($ct_result->errstr)){
		    if (preg_match('//u', $ct_result->errstr)){
            		    $err_str = preg_replace('/^[^\*]*?\*\*\*|\*\*\*[^\*]*?$/iu', '', $ct_result->errstr);
		    }else{
            		    $err_str = preg_replace('/^[^\*]*?\*\*\*|\*\*\*[^\*]*?$/i', '', $ct_result->errstr);
		    }
            }else{
		    if (preg_match('//u', $ct_result->comment)){
			    $err_str = preg_replace('/^[^\*]*?\*\*\*|\*\*\*[^\*]*?$/iu', '', $ct_result->comment);
		    }else{
			    $err_str = preg_replace('/^[^\*]*?\*\*\*|\*\*\*[^\*]*?$/i', '', $ct_result->comment);
		    }
	    }

            $ret_val['errstr'] = $err_str;
            
            CEventLog::Add(array(
                'SEVERITY' => 'SECURITY',
                'AUDIT_TYPE_ID' => 'CLEANTALK_E_SERVER',
                'MODULE_ID' => 'cleantalk.antispam',
                'DESCRIPTION' => $err_str
            ));

            if($bSendEmail){
                $send_flag = FALSE;
                $insert_flag = FALSE;
                $time = $DB->Query('SELECT ct_value FROM cleantalk_timelabels WHERE ct_key=\''. $timelabels_key .'\'')->Fetch();
                if($time === FALSE){
                    $send_flag = TRUE;
                    $insert_flag = TRUE;
                }elseif(time()-900 > $time['ct_value']) {		// 15 minutes
                    $send_flag = TRUE;
                    $insert_flag = FALSE;
                }
                if($send_flag){
                    if($insert_flag){
                        $arInsert = $DB->PrepareInsert('cleantalk_timelabels', array('ct_key'=>$timelabels_key, 'ct_value' => time()));
                        $strSql = 'INSERT INTO cleantalk_timelabels('.$arInsert[0].') VALUES ('.$arInsert[1].')';
                    }else{
                        $strUpdate = $DB->PrepareUpdate('cleantalk_timelabels', array('ct_value' => time()));
                        $strSql = 'UPDATE cleantalk_timelabels SET '.$strUpdate.' WHERE ct_key = \''. $timelabels_key .'\'';
                    }
                    $DB->Query($strSql);
                    bxmail(
                        COption::GetOptionString("main", "email_from"),
                        $err_title,
                        $err_str
                    );
                }
            }
            return $ret_val;
        }

        $ret_val['errno'] = 0;
        if ($ct_result->allow == 1) {
            // Not spammer.
            $ret_val['allow'] = 1;
            $GLOBALS['ct_request_id'] = $ct_result->id;
        }else{
            $ret_val['allow'] = 0;
            $ret_val['ct_result_comment'] = $ct_result->comment;
            // Spammer.
            // Check stop_queue flag.
            if($type == 'comment' && $ct_result->stop_queue == 0) {
                // Spammer and stop_queue == 0 - to manual approvement.
                $ret_val['stop_queue'] = 0;
                $GLOBALS['ct_request_id'] = $ct_result->id;
                $GLOBALS['ct_result_comment'] = $ct_result->comment;
            }else{
                // New user or Spammer and stop_queue == 1 - display message and exit.
                $ret_val['stop_queue'] = 1;
            }
        }
        return $ret_val;
    }

    /**
     * Addon to CheckAllBefore method after comments/messages checking
     * It fills special CleanTalk tables according to CleanTalk result
     *  for better spam accounting 
     *  and logs CleanTalk events
     * Use it in your modules
     * You must call it from OnAfter* events in comment/messages checking
     * @param string Name of event generated module ('blog', 'forum', etc.)
     * @param int ID of added entity (comment, message, etc)
     * @param string System log event prefix, for logging
     */
    static function CheckCommentAfter($module, $cid, $log_event = '') {
        global $DB;
        if(empty($module))
            return;
        if(empty($cid) || intval($cid) < 0)
            return;

        if(isset($GLOBALS['ct_request_id'])) {
            try {
                $arInsert = $DB->PrepareInsert(
                    'cleantalk_cids',
                    array(
                        'module' => $module,
                        'cid' => intval($cid),
                        'ct_request_id' => $GLOBALS['ct_request_id'],
                        'ct_result_comment' => isset($GLOBALS['ct_result_comment']) ? $GLOBALS['ct_result_comment'] : ''
                    )
                );
                $strSql = 'INSERT INTO cleantalk_cids('.$arInsert[0].') VALUES ('.$arInsert[1].')';
                $DB->Query($strSql);
            } catch (Exception $e){}
            // Log CleanTalk event
            if(isset($GLOBALS['ct_result_comment'])){
                CEventLog::Add(array(
                    'SEVERITY' => 'SECURITY',
                    'AUDIT_TYPE_ID' => 'CLEANTALK_EVENT',
                    'MODULE_ID' => $module,
                    'ITEM_ID' => (empty($log_event) ? $module . ', mess[' . $cid . ']' : $log_event),
                    'DESCRIPTION' => $GLOBALS['ct_result_comment']
                ));
            }
            unset($GLOBALS['ct_request_id']);
        }
    }

    /**
     * Sending of manual moderation result to CleanTalk server
     * It makes CleanTalk service better
     * Use it in your modules
     * @param string Name of event generated module ('blog', 'forum', etc.)
     * @param int ID of added entity (comment, message, etc)
     * @param string Feedback type - 'Y' or 'N' only
     */
    static function SendFeedback($module, $id, $feedback) {
        global $APPLICATION, $DB;
        if(empty($module))
            return;
        if(empty($id) || intval($id) < 0)
            return;
        if(empty($feedback) || $feedback != 'Y' && $feedback != 'N')
            return;

        $request_id = $DB->Query('SELECT ct_request_id FROM cleantalk_cids WHERE module=\''. $module .'\' AND cid=' . $id)->Fetch();
        if($request_id !== FALSE){
    	    $DB->Query('DELETE FROM cleantalk_cids WHERE module=\''. $module .'\' AND cid=' . $id);
            require_once(dirname(__FILE__) . '/classes/general/cleantalk.class.php');

            $ct_key = COption::GetOptionString('cleantalk.antispam', 'key', '0');
            $ct_ws = self::GetWorkServer();

            $ct = new Cleantalk();
            $ct->work_url = $ct_ws['work_url'];
            $ct->server_url = $ct_ws['server_url'];
            $ct->server_ttl = $ct_ws['server_ttl'];
            $ct->server_changed = $ct_ws['server_changed'];

            $ct_request = new CleantalkRequest();
            $ct_request->auth_key = $ct_key;
            $ct_request->agent = 'bitrix-370';
	    $ct_request->sender_ip = $ct->ct_session_ip($_SERVER['REMOTE_ADDR']);
            $ct_request->feedback = $request_id . ':' . ($feedback == 'Y' ? '1' : '0');

            $ct->sendFeedback($ct_request);
        }
    }
    
    /**
     * Gets CleanTalk resume for spam detection by id
     * Use it in your modules/templates, see example
     * @param string Name of event generated module ('blog', 'forum', etc.)
     * @param int ID of entity (comment, message, etc)
     * @return string|boolean Text of CleanTalk resume if any or FALSE if not
     */
    static function GetCleanTalkResume($module, $id) {
        global $APPLICATION, $DB;
        if(empty($module))
            return;
        if(empty($id) || intval($id) < 0)
            return;

        $ret_val = $DB->Query('SELECT ct_request_id, ct_result_comment FROM cleantalk_cids WHERE module=\''. $module .'\' AND cid=' . $id)->Fetch();
        return $ret_val;
    }
    
    /**
     * *** Inner methods section ***
     */

    /**
     * CleanTalk inner function - gets working server.
     */
    private static function GetWorkServer() {
        global $DB;
        $result = $DB->Query('SELECT work_url,server_url,server_ttl,server_changed FROM cleantalk_server LIMIT 1')->Fetch();
        if($result !== FALSE)
            return array(
                'work_url' => $result['work_url'],
                'server_url' => $result['server_url'],
                'server_ttl' => $result['server_ttl'],
                'server_changed' => $result['server_changed'],
            );
        else
            return array(
                'work_url' => 'http://moderate.cleantalk.ru',
                'server_url' => 'http://moderate.cleantalk.ru',
                'server_ttl' => 0,
                'server_changed' => 0,
            );
    }

    /**
     * CleanTalk inner function - sets working server.
     */
    private static function SetWorkServer($work_url = 'http://moderate.cleantalk.ru', $server_url = 'http://moderate.cleantalk.ru', $server_ttl = 0, $server_changed = 0) {
        global $DB;
        $count = $DB->Query('SELECT count(*) AS count FROM cleantalk_server')->Fetch();
        if($count == 0){
            $arInsert = $DB->PrepareInsert(
                'cleantalk_server',
                array(
                    'work_url' => $work_url,
                    'server_url' => $server_url,
                    'server_ttl' => $server_ttl,
                    'server_changed' => $server_changed,
                )
            );
            $strSql = 'INSERT INTO cleantalk_server('.$arInsert[0].') VALUES ('.$arInsert[1].')';
        }else{
            $strUpdate = $DB->PrepareUpdate(
                'cleantalk_server',
                array(
                    'work_url' => $work_url,
                    'server_url' => $server_url,
                    'server_ttl' => $server_ttl,
                    'server_changed' => $server_changed,
                )
            );
            $strSql = 'UPDATE cleantalk_server SET '.$strUpdate;
        }
        $DB->Query($strSql);
    }

    /**
     * CleanTalk inner function - sets JavaScript checking values and returns last added one.
     */
    private static function SetCheckJSValues() {
        global $DB;
	$current_time_range = date('H'); // time range key is current hour

	$flag_update = FALSE;
        $db_result = $DB->Query('SELECT time_range,js_values FROM cleantalk_checkjs LIMIT 1')->Fetch();
        if($db_result !== FALSE){
	    $db_time_range = $db_result['time_range'];
	    $db_js_values = array_slice(explode(' ', $db_result['js_values'], self::KEYS_NUM+1), 0, self::KEYS_NUM);
	    if($db_time_range == $current_time_range){
		return $db_js_values;
	    }else{
		$flag_update = TRUE;
	    }
	}else{
	    $db_js_values = array();
	}

    	$arFields = array(
            'time_range' => $current_time_range,
            'js_values'  => implode(' ', array_merge( array(md5(date(DATE_RSS).'+'.(string)rand())), array_slice($db_js_values,0,self::KEYS_NUM-1) ))
        );
	if($flag_update){
            $strUpdate = $DB->PrepareUpdate(
                'cleantalk_checkjs',
                $arFields
            );
            $strSql = 'UPDATE cleantalk_checkjs SET '.$strUpdate . " WHERE time_range='" . $DB->ForSql($db_time_range)."'";
	}else{
            $arInsert = $DB->PrepareInsert(
                'cleantalk_checkjs',
                $arFields
            );
            $strSql = 'INSERT INTO cleantalk_checkjs('.$arInsert[0].') VALUES ('.$arInsert[1].')';
	}
        $res = $DB->Query($strSql, TRUE);
	return self::GetCheckJSValues();
    }
    /**
     * CleanTalk inner function - gets current JavaScript checking values.
     */
    private static function GetCheckJSValues() {
        global $DB;
        $db_result = $DB->Query('SELECT time_range,js_values FROM cleantalk_checkjs LIMIT 1')->Fetch();
        if($db_result !== FALSE){
	    return array_slice(explode(' ', $db_result['js_values'], self::KEYS_NUM+1), 0, self::KEYS_NUM);
	}else{
	    return array(md5(COption::GetOptionString('cleantalk.antispam', 'key', '0') . '+' . COption::GetOptionString('main', 'email_from')));
	}
    }

}
?>
