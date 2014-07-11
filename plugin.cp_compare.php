<?php
/*****************************************************************************************
plugin.cp_compare.php
widget for showing local sector-times

@version 4.0a
@author aca
some code taken and adapted from already existing cp-plugins
(e.g. spykeallcps, best_cp_times, personal_best_cps)

******************************************************************************************/


Aseco::registerEvent ( 'onStartup', 'cpc_startup' );//$aseco

Aseco::registerEvent('onPlayerConnect', 'cpc_playerConnect');//$aseco, $player
Aseco::registerEvent('onPlayerDisconnect', 'cpc_playerDisconnect');//$aseco, $player
Aseco::registerEvent('onPlayerInfoChanged', 'cpc_playerInfoChanged');//$aseco, $changes

Aseco::registerEvent ( 'onBeginMap', 'cpc_beginMap' );//$aseco, $map

Aseco::registerEvent ( 'onLocalRecord', 'cpc_localRecord' );//$aseco, $record
Aseco::registerEvent ( 'onPlayerFinish', 'cpc_playerFinish' );//$aseco, $record
Aseco::registerEvent ( 'onCheckpoint', 'cpc_checkpoint' );//$aseco, $command

Aseco::registerEvent ( 'onEndRound', 'cpc_endRound' );//$aseco



global $cpc;

function cpc_startup($aseco){
	global $cpc;
	$cpc = new CpCompare();
}

function cpc_playerConnect($aseco, $player){
	global $cpc;
	$recTime = '';
	//if player is already in local records
	if(isset($cpc->localsLogins[$player->login])){
		$recTime = $cpc->localsLogins[$player->login]['cps'][$cpc->finishCp];
	}
	$cpc->show_widget($aseco, $player->login, $recTime, $cpc->finishCp);
}

function cpc_playerDisconnect($aseco, $player){
	global $cpc;
	unset($cpc->xmlArray[$player->login]);
	unset($cpc->specArray[$player->login]);
}

function cpc_playerInfoChanged ($aseco, $changes){
	global $cpc;
	$login = $changes['Login'];
	$spectatorStatus = $changes['SpectatorStatus'];
	$isSpec = $spectatorStatus % 10000;
	
	//if status changed to spectator
	if($isSpec){
		$spectatorLogin = $changes['Login'];
		$spectatorID = $changes['PlayerId'];
		$spectatedID = (int) ($spectatorStatus / 10000);
		
		//is a player spectated
		if($spectatedID > 0 && $spectatedID < 255){
			//fetch login of spectatedID
			$aseco->client->query('GetPlayerList',254,0);//max number of infos, starting-index
			$playerList = $aseco->client->getResponse();
			$spectatedLogin = '';
			foreach($playerList as $player){
				$pID = $player['PlayerId'];
				if($pID == $spectatedID){
					$spectatedLogin = $player['Login'];
					break;
				}
			}			
			$cpc->specArray[$spectatorLogin] = $spectatedLogin;
			
			//show instantly cp-widget of spectated
			$aseco->client->query("SendDisplayManialinkPageToLogin", $spectatorLogin, $cpc->xmlArray[$spectatedLogin], 0, false);
		}
		else{//is free-spec
			unset($cpc->specArray[$spectatorLogin]);
			//don't show the widget
			$cpc->hide_widget($aseco, $spectatorLogin);
		}
	}
	//if status changed from spectator to player
	elseif (isset($clc->specArray[$login])){
		unset($clc->specArray[$login]);
		//show own widget
		$aseco->client->query("SendDisplayManialinkPageToLogin", $spectatorLogin, $cpc->xmlArray[$spectatorLogin], 0, false);
	}

}

function cpc_beginMap($aseco, $map){
	global $cpc;
	$cpc->finishCp = $map->nbchecks-1;
	$cpc->fetch_data($aseco);
}

function cpc_localRecord($aseco, $record){
	global $cpc;
	$cpc->fetch_data($aseco);
}

function cpc_playerFinish($aseco, $command){
 	//when no "real" finish but a restart
	if($command->score == 0){
		global $cpc;
		$login = $command->player->login;
		$recTime = '';
		
		//if player is already in local records
		if(isset($cpc->localsLogins[$login])){
			$recTime = $cpc->localsLogins[$login]['cps'][$cpc->finishCp];
		}
		
		$cpc->fetch_data($aseco);
		$cpc->show_widget($aseco, $login, $recTime, $cpc->finishCp);
		//also show to spectators
		foreach ($cpc->specArray as $spectator => $spectated){
			if($spectated == $login){
				$aseco->client->query("SendDisplayManialinkPageToLogin", $spectator, $cpc->xmlArray[$spectated], 0, false);
			}
		}
	}	
}

function cpc_checkpoint($aseco, $command){
	global $cpc;
	
	$login = $command[1];
	$time = $command[2];
	$cp = $command[4];	

	$cpc->fetch_data($aseco);
	$cpc->show_widget($aseco, $login, $time, $cp);
	
	//also show to spectators
	foreach ($cpc->specArray as $spectator => $spectated){
		if($spectated == $login){
			$aseco->client->query("SendDisplayManialinkPageToLogin", $spectator, $cpc->xmlArray[$spectated], 0, false);
		}
	}
}


function cpc_endRound($aseco){
	global $cpc;
	$cpc->hide_widget($aseco);
}





class CpCompare{
	public $specArray;//['spectatorLogin'] = spectatedLogin
	public $xmlArray;//['login'] = currentWidgetXML
	
	//array with local records, key is login
	public $localsLogins; //locals['login']['rank']; locals ['login']['nick']; locals ['login']['cps']

	public $finishCp;
	
	private $settings;
	private $recCount;//number of local records
	private $first; //first['login']; first['cps'];	
	
	//array that stores for each lap the finish-time (key is login)
	//used for work-around when in laps-mode
	private $finishTimes;//finishTimes[login][lap] = time	
	
	//array with local records, key is rank
	private $localsRanked; //locals[rank]['login']; locals[rank]['nick']; locals[rank]['cps']
	
	
	
	function CpCompare(){
		$this->settings = simplexml_load_file('cp_compare.xml');
		setCustomUIField('checkpoint_list', false);
		$this->finishTimes = array();
		$this->specArray = array();
		$this->xmlArray = array();
	}
	
	
	function formatTime($ms) {
		$minutes = $ms / 60000;
		
		$seconds = ($ms % 60000 )/ 1000;
		$tseconds = ($ms % 60000) % 1000;
		
		$res = sprintf('%02d:%02d.%03d', $minutes, $seconds, $tseconds);
	
		return $res;
	}
	
	
	function getXML($first, $beforeMe, $me, $myRank, $logins){
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml .= '<manialink id="cp_compare2" version="1" timeout="0">';
		
		//widget_frame
		$xml .= '<frame posn="'.$this->settings->widget_frame->posn->x.' '.$this->settings->widget_frame->posn->y.' '.$this->settings->widget_frame->posn->z.'" ';
		$xml .= ' scale="'.$this->settings->widget_frame->scale.'">';
		
		
		//***************** headline ***************************
		//rec
		$xml .= '<quad posn="'.$this->settings->rec->posn->x.' '.($this->settings->rec->posn->y + $this->settings->lineHeight).' '.$this->settings->rec->posn->z.'" ';
		$xml .= 'valign="'.$this->settings->rec->valign.'" halign="'.$this->settings->rec->halign.'" ';
		$xml .= 'sizen="'.$this->settings->rec->width.' '.$this->settings->lineHeight.'" bgcolor="'.$this->settings->headlineColor.'" />';
		
		$xml .= '<label posn="'.($this->settings->rec->posn->x + 0.5) .' '.($this->settings->rec->posn->y + $this->settings->lineHeight).' '.$this->settings->rec->posn->z.'" ';
		$xml .= 'valign="'.$this->settings->rec->valign.'" halign="'.$this->settings->rec->halign.'" ';
		$xml .= 'sizen="'.$this->settings->rec->width.' '.$this->settings->lineHeight.'" textsize="'.$this->settings->rec->textsize.'" ';
		$xml .= 'textcolor="'.$this->settings->headline->textColor.'" text="'.$this->settings->headline->rec.'" />';
		
		//data
		$xml .= '<quad posn="'.$this->settings->data->posn->x .' '.($this->settings->rec->posn->y + $this->settings->lineHeight).' '.$this->settings->data->posn->z.'" ';
		$xml .= 'valign="'.$this->settings->data->valign.'" halign="'.$this->settings->data->halign.'" ';
		$xml .= 'sizen="'.$this->settings->data->width.' '.$this->settings->lineHeight.'" bgcolor="'.$this->settings->headlineColor.'"/> ';
		
		$xml .= '<label posn="'.($this->settings->data->posn->x + 0.5) .' '.($this->settings->rec->posn->y + $this->settings->lineHeight).' '.$this->settings->data->posn->z.'" ';
		$xml .= 'valign="'.$this->settings->data->valign.'" halign="'.$this->settings->data->halign.'" ';
		$xml .= 'sizen="'.$this->settings->data->width.' '.$this->settings->lineHeight.'" textsize="'.$this->settings->data->textsize.'" ';
		$xml .= 'textcolor="'.$this->settings->headline->textColor.'" text="'.$this->settings->headline->data.'" />';
		
		//name
		$xml .= '<quad posn="'.$this->settings->names->posn->x.' '.($this->settings->rec->posn->y + $this->settings->lineHeight).' '.$this->settings->names->posn->z.'" ';
		$xml .= 'valign="'.$this->settings->names->valign.'" halign="'.$this->settings->names->halign.'" ';
		$xml .= 'sizen="'.$this->settings->names->width.' '.$this->settings->lineHeight.'" bgcolor="'.$this->settings->headlineColor.'" />';
		
		$xml .= '<label posn="'.($this->settings->names->posn->x + 0.5).' '.($this->settings->rec->posn->y + $this->settings->lineHeight).' '.$this->settings->names->posn->z.'" ';
		$xml .= 'valign="'.$this->settings->names->valign.'" halign="'.$this->settings->names->halign.'" ';
		$xml .= 'sizen="'.$this->settings->names->width.' '.$this->settings->lineHeight.'" textsize="'.$this->settings->names->textsize.'" ';
		$xml .= 'textcolor="'.$this->settings->headline->textColor.'" text="'.$this->settings->headline->name.'" />';
		
//***********************************************************************************************************************************************************************
	
		
	//***************** first ***************************
		//first_rec
		$xml .= '<quad posn="'.$this->settings->rec->posn->x.' '.$this->settings->rec->posn->y.' '.$this->settings->rec->posn->z.'" ';
		$xml .= 'valign="'.$this->settings->rec->valign.'" halign="'.$this->settings->rec->halign.'" ';
		$xml .= 'sizen="'.$this->settings->rec->width.' '.$this->settings->lineHeight.'" bgcolor="'.$this->settings->firstColor.'" />';
		
		$xml .= '<label posn="'.($this->settings->rec->posn->x + 0.5) .' '.$this->settings->rec->posn->y.' '.$this->settings->rec->posn->z.'" ';
		$xml .= 'valign="'.$this->settings->rec->valign.'" halign="'.$this->settings->rec->halign.'" ';
		$xml .= 'sizen="'.$this->settings->rec->width.' '.$this->settings->lineHeight.'" textsize="'.$this->settings->rec->textsize.'" ';
		$xml .= 'textcolor="'.$this->settings->recColor.'" text="'.(($this->recCount > 0 && $myRank != 1 ) ? '$s$o1.' : '').'" />';
		
		//first_data
		$xml .= '<quad posn="'.$this->settings->data->posn->x .' '.$this->settings->data->posn->y.' '.$this->settings->data->posn->z.'" ';
		$xml .= 'valign="'.$this->settings->data->valign.'" halign="'.$this->settings->data->halign.'" ';
		$xml .= 'sizen="'.$this->settings->data->width.' '.$this->settings->lineHeight.'" bgcolor="'.$this->settings->firstColor.'"/> ';
		
		$xml .= '<label posn="'.($this->settings->data->posn->x + 0.5) .' '.$this->settings->data->posn->y.' '.$this->settings->data->posn->z.'" ';
		$xml .= 'valign="'.$this->settings->data->valign.'" halign="'.$this->settings->data->halign.'" ';
		$xml .= 'sizen="'.$this->settings->data->width.' '.$this->settings->lineHeight.'" textsize="'.$this->settings->data->textsize.'" ';
		$xml .= 'text="$o'.$first.'" />';		
		
		//first_name
		$xml .= '<quad posn="'.$this->settings->names->posn->x.' '.$this->settings->names->posn->y.' '.$this->settings->names->posn->z.'" ';
		$xml .= 'valign="'.$this->settings->names->valign.'" halign="'.$this->settings->names->halign.'" ';
		$xml .= 'sizen="'.$this->settings->names->width.' '.$this->settings->lineHeight.'" bgcolor="'.$this->settings->firstColor.'" />';
		
		$xml .= '<label posn="'.($this->settings->names->posn->x + 0.5).' '.$this->settings->names->posn->y.' '.$this->settings->names->posn->z.'" ';
		$xml .= 'valign="'.$this->settings->names->valign.'" halign="'.$this->settings->names->halign.'" ';
		$xml .= 'sizen="'.$this->settings->names->width.' '.$this->settings->lineHeight.'" textsize="'.$this->settings->names->textsize.'" ';
		$xml .= 'text="'.$logins['first'].'" />';
		
		
		//***************** beforeMe ***************************		
		//before_me_rec
		$xml .= '<quad posn="'.$this->settings->rec->posn->x.' '.($this->settings->rec->posn->y - $this->settings->lineHeight).' '.$this->settings->rec->posn->z.'" ';
		$xml .= 'valign="'.$this->settings->rec->valign.'" halign="'.$this->settings->rec->halign.'" ';
		$xml .= 'sizen="'.$this->settings->rec->width.' '.$this->settings->lineHeight.'" bgcolor="'.$this->settings->beforeMeColor.'" />';
		
		$xml .= '<label posn="'.($this->settings->rec->posn->x + 0.5) .' '.($this->settings->rec->posn->y - $this->settings->lineHeight).' '.$this->settings->rec->posn->z.'" ';
		$xml .= 'valign="'.$this->settings->rec->valign.'" halign="'.$this->settings->rec->halign.'" ';
		$xml .= 'sizen="'.$this->settings->rec->width.' '.$this->settings->lineHeight.'" textsize="'.$this->settings->rec->textsize.'" ';
		$rankBefore = $myRank - 1;
		$xml .= 'textcolor="'.$this->settings->recColor.'" text="'.(($myRank > 2) ? "\$s\$o$rankBefore." : '').'" />';
		
		//before_me_data
		$xml .= '<quad posn="'.$this->settings->data->posn->x .' '.($this->settings->rec->posn->y - $this->settings->lineHeight).' '.$this->settings->data->posn->z.'" ';
		$xml .= 'valign="'.$this->settings->data->valign.'" halign="'.$this->settings->data->halign.'" ';
		$xml .= 'sizen="'.$this->settings->data->width.' '.$this->settings->lineHeight.'" bgcolor="'.$this->settings->beforeMeColor.'"/> ';
		
		$xml .= '<label posn="'.($this->settings->data->posn->x + 0.5).' '.($this->settings->data->posn->y - $this->settings->lineHeight).' '.$this->settings->data->posn->z.'" ';
		$xml .= 'valign="'.$this->settings->data->valign.'" halign="'.$this->settings->data->halign.'" ';
		$xml .= 'sizen="'.$this->settings->data->width.' '.$this->settings->lineHeight.'" textsize="'.$this->settings->data->textsize.'" ';
		$xml .= 'text="$o'.$beforeMe.'" />';
		
		//before_me_name
		$xml .= '<quad posn="'.$this->settings->names->posn->x.' '.($this->settings->rec->posn->y - $this->settings->lineHeight).' '.$this->settings->names->posn->z.'" ';
		$xml .= 'valign="'.$this->settings->names->valign.'" halign="'.$this->settings->names->halign.'" ';
		$xml .= 'sizen="'.$this->settings->names->width.' '.$this->settings->lineHeight.'" bgcolor="'.$this->settings->beforeMeColor.'" />';
		
		$xml .= '<label posn="'.($this->settings->names->posn->x + 0.5).' '.($this->settings->names->posn->y - $this->settings->lineHeight).' '.$this->settings->names->posn->z.'" ';
		$xml .= 'valign="'.$this->settings->names->valign.'" halign="'.$this->settings->names->halign.'" ';
		$xml .= 'sizen="'.$this->settings->names->width.' '.$this->settings->lineHeight.'" textsize="'.$this->settings->names->textsize.'" ';
		$xml .= 'text="'.$logins['beforeMe'].'" />';		
		

		//***************** me ***************************		
		//me_rec
		$xml .= '<quad posn="'.$this->settings->rec->posn->x.' '.($this->settings->rec->posn->y - $this->settings->lineHeight *2).' '.$this->settings->rec->posn->z.'" ';
		$xml .= 'valign="'.$this->settings->rec->valign.'" halign="'.$this->settings->rec->halign.'" ';
		$xml .= 'sizen="'.$this->settings->rec->width.' '.$this->settings->lineHeight.'" bgcolor="'.$this->settings->meColor.'" />';
		
		$xml .= '<label posn="'.($this->settings->rec->posn->x + 0.5) .' '.($this->settings->rec->posn->y - $this->settings->lineHeight *2) .' '.$this->settings->rec->posn->z.'" ';
		$xml .= 'valign="'.$this->settings->rec->valign.'" halign="'.$this->settings->rec->halign.'" ';
		$xml .= 'sizen="'.$this->settings->rec->width.' '.$this->settings->lineHeight.'" textsize="'.$this->settings->rec->textsize.'" ';
		$xml .= 'textcolor="'.$this->settings->recColor.'" text="$s$o'.$myRank.'." />';
		
		//me_data
		$xml .= '<quad posn="'.$this->settings->data->posn->x .' '.($this->settings->rec->posn->y - $this->settings->lineHeight *2).' '.$this->settings->data->posn->z.'" ';
		$xml .= 'valign="'.$this->settings->data->valign.'" halign="'.$this->settings->data->halign.'" ';
		$xml .= 'sizen="'.$this->settings->data->width.' '.$this->settings->lineHeight.'" bgcolor="'.$this->settings->meColor.'"/> ';
		
		$xml .= '<label posn="'.($this->settings->data->posn->x + 0.5).' '.($this->settings->data->posn->y - $this->settings->lineHeight *2) .' '.$this->settings->data->posn->z.'" ';
		$xml .= 'valign="'.$this->settings->data->valign.'" halign="'.$this->settings->data->halign.'" ';
		$xml .= 'sizen="'.$this->settings->data->width.' '.$this->settings->lineHeight.'" textsize="'.$this->settings->data->textsize.'" ';
		$xml .= 'text="$o'.$me.'" />';
		
		//me_name
		$xml .= '<quad posn="'.$this->settings->names->posn->x.' '.($this->settings->rec->posn->y - $this->settings->lineHeight *2).' '.$this->settings->names->posn->z.'" ';
		$xml .= 'valign="'.$this->settings->names->valign.'" halign="'.$this->settings->names->halign.'" ';
		$xml .= 'sizen="'.$this->settings->names->width.' '.$this->settings->lineHeight.'" bgcolor="'.$this->settings->meColor.'" />';
		
		$xml .= '<label posn="'.($this->settings->names->posn->x + 0.5).' '.($this->settings->names->posn->y - $this->settings->lineHeight *2) .' '.$this->settings->names->posn->z.'" ';
		$xml .= 'valign="'.$this->settings->names->valign.'" halign="'.$this->settings->names->halign.'" ';
		$xml .= 'sizen="'.$this->settings->names->width.' '.$this->settings->lineHeight.'" textsize="'.$this->settings->names->textsize.'" ';
		$xml .= 'text="'.$logins['me'].'" />';
		
//***********************************************************************************************************************************************************************
		$xml .= '</frame>';
		$xml .= '</manialink>';
		
		return $xml;
		
	}

	//sets localsRanked, localsLogins and first-array
	function fetch_data($aseco){
		unset($this->first);
		unset($this->localsRanked);
		unset($this->localsLogins);
		$locals1 = array();
		$locals2 = array();
		$this->recCount = $aseco->server->records->count();
		
		//are there any local records
		if($this->recCount > 0){
			$i = 0;
			while($i < $this->recCount){
				//fetch logins, nicks and cps of local records
				$login = $aseco->server->records->getRecord($i)->player->login;
				$nick = $aseco->server->records->getRecord($i)->player->nickname;
				$cps = $aseco->server->records->getRecord($i, true)->checks;
				
				$locals1[$i+1] = array(
					"login" => $login,
					"nick" => $nick,
					"cps" => $cps);
				
				$locals2[$login] = array(
					"rank" => $i+1,
					"nick" => $nick,
					"cps" => $cps);
				
				$i++;			
			}
			
			$this->localsRanked = $locals1;
			$this->localsLogins = $locals2;
			$this->first = $locals1[1];
		}
		
	}

	function show_widget($aseco, $login, $time, $cp){
		$first = '';
		$beforeMe = '';
		$me = 'no record';
		$myRank = '0';
		$logins = array(
			"first" => '',
			"beforeMe" => '',
			"me" => ''.$aseco->server->players->getPlayer($login)->nickname);

			
		if($time != ''){
			//gamemode Laps->adjust cp-count and cp-time
			if($aseco->server->gameinfo->mode == '4'){
				$cpOrig	= $cp;
				$cpNumber = $cp + 1;
				
				$numCps = $this->finishCp + 1;

				
				$lap = (int) ($cpNumber / $numCps);
				
				//reset cp to value of first lap
				if($cpNumber > $numCps){
					$cp = $cpOrig % $numCps;
				}
				
				if($cp == $this->finishCp){
					//save finish-cp-times
					$this->finishTimes[$login][$lap] = $time;
				}
				
				//special case first time crossing start-finishcp
				$this->finishTimes[$login][0] = 0;
				
				//take away time formerly driven
				if($lap > 0){
					//at start-finish-cp
					if($cp == $this->finishCp){
						$timeFdriven = $this->finishTimes[$login][$lap-1];
					}
					//at all other cps
					else{
						$timeFdriven = $this->finishTimes[$login][$lap];
					}

					$time = $time - $timeFdriven;
				}
			}
		
		
		
			//are there any local records
			if($this->recCount > 0){
				//difference to first
				$diff2first = $time - $this->first['cps'][$cp];
				
				//positive, negative or zero?
				if($diff2first > 0){
					$first = '$'.$this->settings->positive_cp_color. '+' . $this->formatTime($diff2first);
				}
				else if($diff2first < 0){
					$first = '$'.$this->settings->negative_cp_color. '-' . $this->formatTime($diff2first * (-1));
				}
				else{
					$first = '$'.$this->settings->negative_cp_color. ' ' . $this->formatTime($diff2first);
				}
				
				$logins['first'] = $this->localsRanked[1]['nick'];
				
				//has oneself a local record on this map
				if(isset($this->localsLogins[$login])){
					$myRank = $this->localsLogins[$login]['rank'];
					
					//has oneself the first record
					if($myRank == 1){
						$first = '';
						$logins['first'] = '';
					}
	
					else if($myRank >2){
						$diff2second = $time - $this->localsRanked[$myRank-1]['cps'][$cp];
						//positive, negative or zero?
						if($diff2second > 0){
							$beforeMe = '$'.$this->settings->positive_cp_color. '+' . $this->formatTime($diff2second);
						}
						else if($diff2second < 0){
							$beforeMe = '$'.$this->settings->negative_cp_color. '-' . $this->formatTime($diff2second * (-1));
						}
						else{
							$beforeMe = '$'.$this->settings->negative_cp_color. ' ' .$this->formatTime($diff2second);
						}
						$logins['beforeMe'] = $this->localsRanked[$myRank-1]['nick'];
					}
					
					
					
					//difference to personalBest
					$diff2pb = $time - $this->localsLogins[$login]['cps'][$cp];
					
					//positive, negative or zero?
					if($diff2pb > 0){
						$me = '$'.$this->settings->positive_cp_color. '+' . $this->formatTime($diff2pb);
					}
					else if($diff2pb < 0){
						$me = '$'.$this->settings->negative_cp_color. '-' . $this->formatTime($diff2pb * (-1));
					}
					else{
						$me = '$'.$this->settings->negative_cp_color. ' ' .$this->formatTime($diff2pb);
					}	
				}
			}
		}
		
		$xml = $this->getXML($first, $beforeMe, $me, $myRank, $logins);
		$this->xmlArray[$login] = $xml;
		
		$aseco->client->query("SendDisplayManialinkPageToLogin", $login, $xml, 0, false);
		
		
	}


	function hide_widget($aseco, $login=null){
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml .= '<manialink id="cp_compare2"></manialink>';
	
		if($login != null){
			$aseco->client->query("SendDisplayManialinkPageToLogin", $login, $xml, 0, false);
		}
		else{
			$aseco->client->query ( "SendDisplayManialinkPage", $xml, 0, false );
		}
	}
}




?>
