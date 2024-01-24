<?php
set_time_limit(0);
require_once("php_library/postVarSet.php");
require_once("php_library/sQuote.php");



function SchemeAndHost($url){
	$pu=parse_url($url);
	
	return $pu['scheme'].'://'.$pu['host'];
}
PostVarSet('href',$href);

$homeUrl=SchemeAndHost($href);

$crawlArr=[['href'=>$href, 'parent'=>null]];
$results=[];
$stagedArr=[];

$max=INF;
$run=0;

$dom=new DOMDocument();
function NextCrawl(){
	global $max;
	global $run;
	global $crawlArr;
	global $results;
	global $dom;
	global $homeUrl;
	global $stagedArr;
	
	$run++;
	if(!$crawlArr || $run >= $max){
		return false;
	}
	sleep(1);
	
	
	$findit=array_shift($crawlArr);
	$url=$findit['href'];
	
	if($url === 'RELATIVE ERROR'){
		$results[]=[
			'href'=>'RELATIVE ERROR',
			'data'=>$findit['data']
		];
		return NextCrawl();
	}
	
	$parentIndex=$findit['parent'] ?? -1;
	$noFollow=$findit['noFollow'] ?? false;
	$count300=$findit['count300'] ?? 0;
	
	if($count300 > 5){
		$results[]=[
			'href'=>$url,
			'responseCode'=>'500',
			'data'=>'too many redirects'
		];
	}
	
	if(array_filter($results, function($r) use ($url){return $r['href'] === $url;})){
		return NextCrawl();
	}
	$opts = [
		"http" => [
			"method" => "GET",
			"header" => "User-Agent:WAArawler/1.0\r\n",
		]
	];
	$context = stream_context_create($opts);
	$resp=@file_get_contents($url, false, $context);
	
	
	
	$respCode=intval(explode(' ',$http_response_header[0])[1]);
	
	
	$thisIndex=count($results);
	
	$resultOb=[
		'href'=>$url,
		'responseCode'=>''.$respCode,
		'responseSuccess'=>$respCode <= 400,
		'headers'=>$http_response_header,
		//'body'=>$resp,
		'links'=>[]
	];
	if($parentIndex > -1){
		$resultOb['parentIndex']=$parentIndex;
	}
	$results[]=&$resultOb;
	
	if($respCode >= 400){
		return NextCrawl();
	}
	if($respCode >= 300){
		$loc=array_values(array_filter($http_response_header, function($h){return preg_match('/^Location:/i',$h);}));
		//die(json_encode($loc));,''
		//if(!$loc){die(json_encode($http_response_header));}
		array_unshift($crawlArr,['href'=>trim(preg_replace('/^location:/i','',$loc[0])),'parent'=>$thisIndex,'count300'=>$count300+1]);
		return NextCrawl();
	}

	
	
	$dom->loadHTML($resp,LIBXML_NOERROR);
	
	$anchors=$dom->getElementsByTagName('a');
	
	
	
	foreach($anchors as $a)
	{
		$finditData='';
		
		$link=$a->getAttribute('href');
		//echo "$link<br>";
		if($link === '.'){
			$urlArr=explode('/',$url);
			array_pop($urlArr);
			$newUrl=join('/',$urlArr);
			$newHomeUrl=$homeUrl;
			//echo($newUrl);
		}else if(preg_match('#^/#',$link)){
			$top=SchemeAndHost($url);
			$newUrl=$top.$link;
			$newHomeUrl=$homeUrl;
		}else if(preg_match('#^(http|https)://#',$link)){
			$newUrl=$link;
			$newHomeUrl=SchemeAndHost($link);
			
		}else if(preg_match('#^\.\.#',$link)){
			$linkArr=explode('/',$link);
			
			$tempUrlOb=parse_url($url);
			$urlArr=explode("/",$tempUrlOb['host'].$tempUrlOb['path']);
			
			if(!$urlArr[count($urlArr)-1]){
				array_pop($urlArr);
			}
			while(($linkArr[0] ?? false) === '..')
			{
				array_shift($linkArr);
				array_pop($urlArr);
			}
			if(!$urlArr){
				$newUrl="RELATIVE ERROR";
				$finditData=$link;
			}else{
				$newUrl=$tempUrlOb['scheme'].'://'.join('/',$urlArr).'/'.join('/',$linkArr);
			}
		}else{
			if($url[-1] !== '/'){$link='/'.$link;}
			$newUrl=$url.$link;
			$newHomeUrl=$homeUrl;
		}
		
		$newUrl=preg_replace('/\.$/','',$newUrl);
		
		$resultOb['links'][]=$newUrl;
		
		if(!$noFollow && !array_filter($results,function($r) use ($newUrl){return $r['href'] === $newUrl;})){
			$crawlArr[]=[
				'href'=>$newUrl,
				'parent'=>$thisIndex,
				'data'=>$finditData,
				'noFollow'=>($newHomeUrl !== $homeUrl)
			];
		}
	}
	NextCrawl();
	 
	
	
}
NextCrawl();


die(json_encode([
	'success'=>true,
	'data'=>$results
]));
?>