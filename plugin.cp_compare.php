<?php
/*****************************************************************************************
plugin cp_compare.php
widget for showing local sector-times

@author doe-eye alias d4u alias aca78
some code taken and adapted from already existing cp-plugins
(e.g. spykeallcps, best_cp_times, personal_best_cps)

******************************************************************************************/


Aseco::registerEvent ( 'onStartup', 'cpc_startup' );//$aseco

Aseco::registerEvent ( 'onBeginRound', 'cpc_fetch_data' );//$aseco
Aseco::registerEvent ( 'onLocalRecord', 'cpc_fetch_data' );//$aseco, $record

Aseco::registerEvent ( 'onPlayerFinish', 'cpc_playerFinish' );//$aseco, $record


Aseco::registerEvent ( 'onCheckpoint', 'cpc_show_data' );//$aseco, $checkpoint

Aseco::registerEvent ( 'onEndRound', 'cpc_hide_widget' );//$aseco
Aseco::registerEvent ( 'onEndMap', 'cpc_hide_widget' );//$aseco, $race


global $cpc;

function cpc_startup($aseco){
	global $cpc;
	$cpc = new CpCompare();
	$cpc->settings = simplexml_load_file('cp_compare.xml');
	setCustomUIField('checkpoint_list', false);
	$cpc->finishTimes = array();
}

function cpc_fetch_data($aseco, $command = null){
	global $cpc;
	$cpc->fetch_data($aseco);
}


function cpc_show_data($aseco, $command){
	global $cpc;
	
	$login = $command[1];
	$time = $command[2];
	$cp = $command[4];	

	cpc_fetch_data($aseco, $command);
	
	$cpc->show_data($aseco, $login, $time, $cp);
	
}


function cpc_hide_widget($aseco, $command = null){
	$xml  = '<?xml version="1.0" encoding="UTF-8"?>';
	$xml .= '<manialink id="cp_compare2"></manialink>';
	
	if(isset($command->player->login)){
		$aseco->client->query("SendDisplayManialinkPageToLogin", $command->player->login, $xml, 0, false);
	}
	else{
		$aseco->client->query ( "SendDisplayManialinkPage", $xml, 0, false );
	}
}

function cpc_playerFinish($aseco, $command){
 	//when no "real" finish but a restart
	if($command->score == 0){
		global $cpc;
		$login = $command->player->login;
		$finishCP = $command->map->nbchecks-1;
		$recTime = '';
		if(isset($cpc->localsLogins[$login])){
			$recTime = $cpc->localsLogins[$login]['cps'][$finishCP];
		}
		cpc_hide_widget($aseco, $command);
		cpc_fetch_data($aseco, $command);
		$cpc->show_data($aseco, $login, $recTime, $finishCP);
	}
	
}



class CpCompare{
	public $settings;
		
	private $localsRanked; //locals[rank]['login']; locals[rank]['nick']; locals[rank]['cps']
	public $localsLogins; //locals['login']['rank']; locals ['login']['nick']; locals ['login']['cps']
	private $first; //first['login']; first['cps'];
	public $finishTimes;//finishTimes[login][finishcp] = time
	
	private $recCount;
	
	function formatTime($ms) {
		$minutes = $ms / 60000;
		
		$seconds = ($ms % 60000 )/ 1000;
		$tseconds = ($ms % 60000) % 1000;
		
		$res = sprintf('%02d:%02d.%03d', $minutes, $seconds, $tseconds);
	
		return $res;
	}
	
	function init($aseco){
		$this->fetch_data($aseco);
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

	function fetch_data($aseco){
		$this->first = null;
		$this->localsRanked = null;
		$this->localsLogins = null;
		$locals1 = array();
		$locals2 = array();
		$this->recCount = $aseco->server->records->count();
		
		//are there any local records
		if($this->recCount > 0){
			$i = 0;
			while($i < $this->recCount){
				//fetch logins to local records
				$login = $aseco->server->records->getRecord($i)->player->login;
				$nick = $aseco->server->records->getRecord($i)->player->nickname;
				$cps = $aseco->server->records->getRecord($i, true)->checks;
				$locals1[$i+1] = array(
					"login" => $login,
					"nick" => $nick,
					"cps" => $cps
				);
				$locals2[$login] = array(
					"rank" => $i+1,
					"nick" => $nick,
					"cps" => $cps
				);
				$i++;			
			}
			$this->localsRanked = $locals1;
			$this->localsLogins = $locals2;
			$this->first = $locals1[1];
		}
		
	}

	function show_data($aseco, $login, $time, $cp){
		$first = '';
		$beforeMe = '';
		$me = 'no record';
		$myRank = '0';
		$logins = array(
			"first" => '',
			"beforeMe" => '',
			"me" => '$s$o'.$aseco->server->players->getPlayer($login)->nickname		
		);

			
		if($time != ''){
		
			//gamemode Laps->adjust cp-count and cp-time
			if($aseco->server->gameinfo->mode == '4'){
				$cpOrig	= $cp;
				$cpNumber = $cp + 1;
				
				$numCps = $aseco->server->map->nbchecks;
				$finishCp = $numCps -1;
				
				$lap = (int) ($cpNumber / $numCps);
				
				//cp-count
				if($cpNumber > $numCps){
					$cpInt2 = $cpOrig % $numCps;
					$cp = $cpInt2;
				}
				
				if($cp == $finishCp){
					//save finish-cp-times
					$this->finishTimes[$login][$lap] = $time;
				}
				
				//special case first time crossing start-finishcp
				$this->finishTimes[$login][0] = 0;
				
				//take away time formerly driven
				if($lap > 0){
					//at all cps but start-finish-cp
					$timeFdriven = $this->finishTimes[$login][$lap];
					
					//at start-finish-cp
					if($cp == $finishCp){
						$timeFdriven = $this->finishTimes[$login][$lap-1];
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
				
				$logins['first'] = '$s$o'.$this->localsRanked[1]['nick'];
				
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
						$logins['beforeMe'] = '$s$o'.$this->localsRanked[$myRank-1]['nick'];
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
		$aseco->client->query("SendDisplayManialinkPageToLogin", $login, $xml, 0, false);
	}
	
}




?>
