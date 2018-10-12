<?php

require_once ( 'wikidata.php' ) ;

class MixnMatch {
	public $config ;
	public $wil_local ;
	public $wil_wd ;
	public $wd_sparql_api = 'https://query.wikidata.org/sparql' ;
	private $cookiejar ; # For doPostRequest
	private $browser_agent = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10.10; rv:57.0) Gecko/20100101 Firefox/57.0" ;

	function __construct ( $config_json_url = './config.json' ) {
		$this->config = json_decode ( file_get_contents ( $config_json_url ) ) ;
		$this->wil_wd = new WikidataItemList ;
		$this->wil_local = new WikidataItemList ;
		$this->wil_local->wikidata_api_url = $this->config->mwapi ;
	}

	public function getSPARQL ( $query , $base_url = '' ) {
		if ( $base_url == '' ) $base_url = $this->config->sparql_url ;
		$url = "{$base_url}?format=json&query=" . urlencode($query) ;
		return json_decode(file_get_contents($url)) ;
	}

	public function loadCatalogMappingFromMnM ( $catalog ) {
		return $this->getSPARQL ( "SELECT DISTINCT ?q ?wdq ?extid { ?q wdt:{$this->config->props->catalog} wd:{$catalog} ; wdt:{$this->config->props->ext_id} ?extid OPTIONAL { ?q wdt:{$this->config->props->manual} ?wdq } }" ) ;
	}

	public function getWikidataPropertyForCatalog ( $catalog ) {
		$this->wil_local->loadItem ( $catalog ) ;
		$i = $this->wil_local->getItem ( $catalog ) ;
		if ( !isset($i) ) die ( "Can't find item for catalog {$catalog}\n" ) ;
		if ( !$i->hasClaims($this->config->props->wd_prop) ) die ( "Catalog {$catalog} has no Wikidata property set\n" ) ;
		$wdprop = $i->getFirstString ( $this->config->props->wd_prop ) ;
		return $wdprop ;
	}

	public function loadCatalogMappingFromWD ( $catalog ) {
		$wdprop = $this->getWikidataPropertyForCatalog ( $catalog ) ;
		$sparql = "SELECT ?q ?extid { ?q wdt:{$wdprop} ?extid }" ;
		return $this->getSPARQL ( $sparql , $this->wd_sparql_api ) ;
	}

	public function syncCatalogWithWikidata ( $catalog ) { # INCOMPLETE
		# Get from MnM: ext=>Mnm, ext=>WD
		$j = $this->loadCatalogMappingFromMnM ( $catalog ) ;
		$ext2mnm = [] ;
		$ext2mnm_wdq = [] ;
		foreach ( $j->results->bindings AS $b ) {
			$ext2mnm[$b->extid->value] = preg_replace('|^.*/|','',$b->q->value) ;
			if ( isset($b->wdq) ) $ext2mnm_wdq[$b->extid->value] = preg_replace('|^.*/|','',$b->wdq->value) ;
		}

		# Get from WD: ext=>WD
		$j = $this->loadCatalogMappingFromWD ( $catalog ) ;
		$ext2wd = [] ;
		foreach ( $j->results->bindings AS $b ) {
			$ext2wd[$b->extid->value] = preg_replace('|^.*/|','',$b->q->value) ;
		}

		$wd_prop = $this->getWikidataPropertyForCatalog ( $catalog ) ;
		$qs_mnm = [] ;
		foreach ( $ext2wd AS $ext_id => $wdq ) {
			if ( !isset($ext2mnm[$ext_id]) ) { # Not in MnM
				# TODO
				print "No MnM for {$ext_id}\n" ;
				continue ;
			}
			if ( !isset($ext2mnm_wdq[$ext_id]) ) { # Not in MnM => WD

				$qs = $ext2mnm[$ext_id] . "\t{$this->config->props->manual}\t\"{$wdq}\"" ;
				$qs .= "\t{$this->config->props->by_user}\t\"|Wikidata importer\"" ;
				$qs .= "\t{$this->config->props->matched_on}\t+2018-10-10T00:00:00Z/11" ; # TODO current date
				$qs_mnm[] = $qs ;
				continue ;
			}
			if ( $ext2mnm_wdq[$ext_id] != $wdq ) { # Mismatch
				# TODO
			}
		#	print "Already in MnM: {$ext_id} => {$wdq}\n" ;
		}

		print implode ( "\n" , $qs_mnm ) ;
	}

	// Takes a URL and an array with POST parameters
	public function doPostRequest ( $url , $params = [] , $optional_headers = null ) {
		if ( !isset($this->cookiejar) ) $this->cookiejar = tmpfile() ;
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_COOKIESESSION, true );
		curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookiejar);
		curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookiejar);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
		curl_setopt($ch, CURLOPT_USERAGENT, $this->browser_agent);
		if ($optional_headers !== null) curl_setopt($ch, CURLOPT_HTTPHEADER, $optional_headers);
		$output = curl_exec($ch);
		$info = curl_getinfo($ch);

		curl_close($ch);
		return $output ;
	}

	public function getEditToken () {
		$result = json_decode ( file_get_contents ( "{$this->config->mwapi}?action=query&meta=tokens&type=csrf&format=json" ) ) ;
		return $result->query->tokens->csrftoken ;
	}

	public function doEditEntity ( $q , $data , $summary = '' ) {
		$params = [
			'action' => 'wbeditentity',
			'id' => $q ,
			'summary' => $summary ,
			'token' => $this->getEditToken() ,
			'data' => json_encode($data) ,
			'format' => 'json'
		] ;
		$result = json_decode ( $this->doPostRequest ( $this->config->mwapi , $params ) ) ;
		return $result ;
	}

	public function getNewClaimString ( $prop , $string ) {
		return $this->getNewClaimFromSnak ( $this->getNewSnakString ( $prop , $string ) ) ;
	}

	public function getNewClaimItem ( $prop , $target_q ) {
		return $this->getNewClaimFromSnak ( $this->getNewSnakItem ( $prop , $target_q ) ) ;
	}

	public function getNewClaimDate ( $prop , $time , $precision = 9 ) {
		return $this->getNewClaimFromSnak ( $this->getNewSnakDate ( $prop , $time , $precision ) ) ;
	}

	public function getNewClaimFromSnak ( $snak ) {
		return [ 'type'=>'statement' , 'rank'=>'normal' , 'mainsnak'=>$snak ] ;
	}

	public function getNewSnakFromDatavalue ( $prop , $dv ) {
		return [ 'snaktype'=>'value' , 'property'=>$prop , 'datavalue'=>$dv ] ;
	}

	public function getNewSnakDate ( $prop , $time , $precision = 9 ) {
		return $this->getNewSnakFromDatavalue ( $prop , [ 'type'=>'time' , 'value'=>['time'=>$time,'precision'=>$precision,'calendarmodel'=>'http://www.wikidata.org/entity/Q1985727'] ] ) ;
	}

	public function getNewSnakString ( $prop , $string ) {
		return $this->getNewSnakFromDatavalue ( $prop , [ 'type'=>'string' , 'value'=>$string ] ) ;
	}

	public function getNewSnakItem ( $prop , $target_q ) {
		return $this->getNewSnakFromDatavalue ( $prop , [ 'type'=>'wikibase-entityid' , 'value'=>['entity-type'=>'item','id'=>$target_q,'numeric-id'=>preg_replace('/\D/','',$target_q)] ] ) ;
	}

	
} ;

?>