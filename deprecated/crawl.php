<?php
set_time_limit(0);

// these can be found in my php_library repo
require_once("php_library/postVarSet.php");
require_once("php_library/sQuote.php");

// href is the url you want to crawl.
PostVarSet('href',$href);

function SchemeAndHost($url){
	$pu=parse_url($url);
	
	return $pu['scheme'].'://'.$pu['host'];
}

// homeUrl could be a constant? Next update
$homeUrl=SchemeAndHost($href);

// crawlArr is a que for what needs to be crawled. 
// Here, it is initialized with the $href
/*a crawlArr object:
	{
		href     : string  : req : the endpoint to be crawled
		parent   : integer : opt : the index of the result that added this endpoint to the crawl que
		count300 : integer : opt : how many 30x there had been (to prevent circular redirects)
		noFollow : bool    : opt : if this is set to true, then none of the links on the page are indexed and added to the crawl que,
		data     : string  : opt : only used in the RELATIVE_ERROR special case
	}
*/
$crawlArr=[['href'=>$href, 'parent'=>null]];

// this will store our crawl results.
/* a result object:
	{
		href            : string : the href that was crawled,
		responseCode    : string : the resposne code of the request,
		responseSuccess : bool   : success flag,
		headers         : string : the response headers,
		links           : array  : a list of anchor href values on the page
		data            : string : any extra comments or problems
	}
	
	notice the body isn't saved. Just a ton of data if you did. Trivial to add.
*/
$results=[];

$max=INF;
$run=0;

// dom parse
$dom=new DOMDocument();

function NextCrawl(){
	global $max;
	global $run;
	global $crawlArr;
	global $results;
	global $dom;
	global $homeUrl;
	
	// This is so you can set a maximum execution count.
	$run++;
	if(!$crawlArr || $run >= $max){
		return false;
	}
	
	// Best practice is to sleep between 0.75 and 1 seconds between requests.
	sleep(1);
	
	
	// gets the next array member 
	$findit=array_shift($crawlArr);
	$url=$findit['href'];
	
	// This is a special case from the end of the NextCrawl function, where we can't resolve the next URL... but we still want to maintain a record that the failure happened.
	if($url === 'RELATIVE ERROR'){
		$results[]=[
			'href'=>'RELATIVE ERROR',
			'data'=>$findit['data'],
			'responseSuccess'=>false
		];
		return NextCrawl();
	}
	
	// parse the optional data from the crawl object
	$parentIndex=$findit['parent'] ?? -1;
	$noFollow=$findit['noFollow'] ?? false;
	$count300=$findit['count300'] ?? 0;
	
	// blocks circular redirects. 
	// this is the fast solution. A redirect array with a search might would be better.
	if($count300 > 5){
		$results[]=[
			'href'=>$url,
			'responseCode'=>'500',
			'data'=>'too many redirects',
			'responseSuccess'=>false
		];
	}
	
	// check if the endpoint has already been crawled, and if it has it moves to the next crawl.
	if(array_filter($results, function($r) use ($url){return $r['href'] === $url;})){
		return NextCrawl();
	}
	
	// run the http request
	$opts = [
		"http" => [
			"method" => "GET",
			"header" => "User-Agent:WAArawler/1.0\r\n",
			'timeout'=> 120
		]
	];
	$context = stream_context_create($opts);
	$resp=@file_get_contents($url, false, $context);
	
	// checks for a complete failure
	if($resp === false){
		$results[]=[
			'href'=>$url,
			'responseCode'=>'599',
			'responseSuccess'=>false,
			'headers'=>'',
			'links'=>[],
			'data'=>'failed file get contents'
			
		];
		return NextCrawl();
	}
	
	// parses out the response code
	$respCode=intval(explode(' ',$http_response_header[0])[1]);
	
	// gets the potential result index, to pass to any children links
	$thisIndex=count($results);
	
	// construct the result object
	$resultOb=[
		'href'=>$url,
		'responseCode'=>''.$respCode,
		'responseSuccess'=>$respCode <= 400,
		'headers'=>$http_response_header,
		'links'=>[]
	];
	
	// if a parent exists, pass that information
	if($parentIndex > -1){
		$resultOb['parentIndex']=$parentIndex;
	}
	
	// ### THIS IS PASSED BY REFERENCE!
	// It is done like this so we can go ahead and return on a 400, but also append to $result['links'] upon crawling
	$results[]=&$resultOb;
	
	
	if($respCode >= 400){
		return NextCrawl();
	}
	
	// if a redirect, make sure to increment count300
	if($respCode >= 300){
		$loc=array_values(array_filter($http_response_header, function($h){return preg_match('/^Location:/i',$h);}));
		array_unshift($crawlArr,[
			'href'=>trim(preg_replace('/^location:/i','',$loc[0])),
			'parent'=>$thisIndex,
			'count300'=>$count300+1,
			'noFollow'=>$noFollow
		]);
		return NextCrawl();
	}

	
	// parse the response
	$dom->loadHTML($resp,LIBXML_NOERROR);
	
	// get anchors
	// TODO add img src, video src, etc.
	$anchors=$dom->getElementsByTagName('a');
	
	
	
	// iterate over the anchors
	foreach($anchors as $a)
	{
		// finditData will be data in the crawl object at the very end
		$finditData='';
		
		// the actual href value
		$link=$a->getAttribute('href');
		
		
		if($link === '.'){
			// special case self reference
			$urlArr=explode('/',$url);
			array_pop($urlArr);
			$newUrl=join('/',$urlArr);
			$newHomeUrl=$homeUrl;
		}else if(preg_match('#^/#',$link)){
			// absolute
			$top=SchemeAndHost($url);
			$newUrl=$top.$link;
			$newHomeUrl=$homeUrl;
		}else if(preg_match('#^(http|https)://#',$link)){
			// complete uri
			$newUrl=$link;
			$newHomeUrl=SchemeAndHost($link);
			
		}else if(preg_match('#^\.\.#',$link)){
			// relative parent folder 
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
			
			// if not urlArr, something funky happened, record the error
			if(!$urlArr){
				$newUrl="RELATIVE ERROR";
				$finditData=$link;
			}else{
				$newUrl=$tempUrlOb['scheme'].'://'.join('/',$urlArr).'/'.join('/',$linkArr);
			}
		}else{
			// regular relative url
			if($url[-1] !== '/'){$link='/'.$link;}
			$newUrl=$url.$link;
			$newHomeUrl=$homeUrl;
		}
		
		$newUrl=preg_replace('/\.$/','',$newUrl);
		
		$resultOb['links'][]=$newUrl;
		
		
		// if the noFollow flag isn't set and the new url hasn't already been crawled, add it to the crawl arr.
		if(!$noFollow && !array_filter($results,function($r) use ($newUrl){return $r['href'] === $newUrl;})){
			$crawlArr[]=[
				'href'=>$newUrl,
				'parent'=>$thisIndex,
				'data'=>$finditData,
				'noFollow'=>($newHomeUrl !== $homeUrl)
			];
		}
	}
	
	// recursively call the next crawl
	NextCrawl();
	 
	
	
}
NextCrawl();


die(json_encode([
	'success'=>true,
	'data'=>$results
]));
?>
