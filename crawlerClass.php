<?php 
namespace WAAWebCrawler;

class Crawler
{
	public function __construct(){
		$this->dom=new \DOMDocument();
		$this->crawlArr=new ArrayOfPotentialCrawls();
		$this->results=new ArrayOfCrawlResults();
	}
	
	
	//set this to '' to disable logging
	public static string $logLocation='crawlerDebug.txt';
	
	// set to the max number of requests to send.
	public static float $maxCrawls=INF;
	
	// your own user-agen to send with the HTTP request
	public static string $userAgent="GenericUA";
	
	
	// parser
	protected \DOMDocument $dom;
	
	// the root of the crawl
	protected string $homeUrl='';
	
	// how many requests have been sent
	protected int $runCount=0;
	
	// state and result tracking
	protected ArrayOfPotentialCrawls $crawlArr;
	protected ArrayOfCrawlResults $results;
	
	// The main interface function... pass the url that you want to crawl
	public function Crawl(string $url, bool $reset=true) : ArrayOfCrawlResults{
		if($reset){$this->ResetLog();}
		
		// reset results
		$this->results=new ArrayOfCrawlResults();
		
		// sets url to a normalized url string
		$this->homeUrl=$this->SchemeAndHost($url);
		
		// reset request count
		$this->runCount=0;
		
		// initialize the crawl arr
		$this->crawlArr=new ArrayOfPotentialCrawls();
		$this->crawlArr[]=new PotentialCrawl($url);
		
		// execute
		return $this->ExecuteCrawls();
	}
	
	
	// just resets the debuggin log.
	public function ResetLog() : void{
		if(self::$logLocation){
			@unlink(self::$logLocation);
		}
	}
	
	// itterate through all of the crawls in the que
	protected function ExecuteCrawls() : ArrayOfCrawlResults{
		
		// crawl while new crawls are in the array, or the request count hasn't exceeded the max.
		while(count($this->crawlArr) && $this->runCount < self::$maxCrawls)
		{
			// sleep if not the first request
			if($this->runCount){
				sleep(1);
			}
			
			// perform the crawl on the next crawl in the que, only push onto results if a real request was sent.
			$crResult=$this->CrawlThisOne($this->crawlArr->Shift());
			if($crResult){
				$this->results[]=$crResult;
			}
		}
		
		// reset crawl array.
		$this->crawlArr=new ArrayOfPotentialCrawls();
		return $this->results;
		
	}
	
	// This is the bread and butter. Send the requests 
	protected function CrawlThisOne(PotentialCrawl $currentCrawl) : ?CrawlResult{
		
		// start the log, if log location is truthy
		if(self::$logLocation){
			file_put_contents(self::$logLocation, $currentCrawl->ToJson()."\n", FILE_APPEND);
		}
		
		// the url being crawled
		$url=$currentCrawl->href;
		
		// RELATIVE ERROR can be pushed onto the que in the case of a relative url that couldn't be resolved. 
		// 		It is here to log the error.
		if($url === 'RELATIVE ERROR'){
			return new CrawlResult([
				'href'=>'RELATIVE ERROR',
				'problem'=>$currentCrawl->problem,
				'responseSuccess'=>false
			]);
		}
		
		// pull some of the data from the current crawl into variables... just for readability, and ease of typing
		$parentResultIndex=$currentCrawl->parentResultIndex;
		$noFollow=$currentCrawl->noFollow;
		$count300=$currentCrawl->count300;
		$nextResultIndex=count($this->results);
		
		
		// too many redirects. 
		if($count300 > 5){
			return new CrawlResult([
				'href'=>$url,
				'responseCode'=>'500',
				'problem'=>'too many redirects',
				'responseSuccess'=>false
			]);
		}
		
		// if the url has already been crawled, return null
		if(array_filter($this->results->getArrayCopy(), function($r) use ($url){return $r->href === $url;})){
			return null;
		}
		
		
		// increment the run count, because a request is about to be sent
		$this->runCount++;
		
		// Send the Request
		$opts=[
			'http'=>[
				'method'=>'GET',
				'header'=>"User-Agent:".self::$userAgent."\r\n", // you can indicate your own user agent
				'timeout'=>120,  								 // so the crawler won't hang if the remote server doesn't respond
				'follow_location'=>false                         // we are going to manually follow any 30* redirects, so we don't want them to automatically be sent.
			]
		];
		$context=stream_context_create($opts);
		$resp=@file_get_contents($url, false, $context);
		
		// if file_get_contents failed in itself, return a failure state
		if($resp === false){
			return new CrawlResult([
				'href'=>$url,
				'responseCode'=>'599',
				'responseSuccess'=>false,
				'headers'=>[],
				'links'=>[],
				'problem'=>'failed file get contents'
			]);
		}
		
		// parse response code
		$respCode=intval(explode(' ',$http_response_header[0])[1]);
		
		// construct a crawl result
		$resultOb=new CrawlResult([
			'href'=>$url,
			'responseCode'=>''.$respCode,
			'responseSuccess'=>$respCode <= 400,
			'headers'=>$http_response_header,
			'links'=>[]
		]);
		
		
		// include the parentResultIndex in the result if appropriate.
		if($parentResultIndex > -1){
			$resultOb->parentResultIndex=$parentResultIndex;
		}
		
		// if logging is on, log the raw result
		if(self::$logLocation){
			file_put_contents(self::$logLocation, $resultOb->ToJson()."\n........................................\n", FILE_APPEND);
		}
		
		
		// if bad response, return now
		if($respCode >= 400){
			return $resultOb;
		}
		
		// handle redirects
		if($respCode >= 300){
			// parse location
			$loc=array_values(array_filter($http_response_header, function($h){return preg_match('/^Location:/i',$h);}));
			
			//unshift a new crawl onto the beginning of the que
			$this->crawlArr->UnShift(new PotentialCrawl(trim(preg_replace('/^location:/i','',$loc[0])),[
				'parentResultIndex'=>$nextResultIndex,
				'count300'=>$count300+1,
				'noFollow'=>$noFollow
			]));
			return $resultOb;
		}
		
		// parse the document
		$this->dom->loadHTML($resp,LIBXML_NOERROR);
	
		// get links, and itterate over them
		$anchors=$this->dom->getElementsByTagName('a');
		
		
		
		foreach($anchors as $a)
		{
			// for storing the relative error.
			$relativeProblem='';
			
			$link=$a->getAttribute('href');
			
				// handle different types
			
			// self path segment
			if($link === '.'){
				$urlArr=explode('/',$url);
				array_pop($urlArr);
				$newUrl=join('/',$urlArr);
				$newHomeUrl=$this->homeUrl;
				
			// relative base url
			}else if(preg_match('#^/#',$link)){
				$top=$this->SchemeAndHost($url);
				$newUrl=$top.$link;
				$newHomeUrl=$this->homeUrl;
				
			//	absolute url
			}else if(preg_match('#^(http|https)://#',$link)){
				$newUrl=$link;
				$newHomeUrl=$this->SchemeAndHost($link);
				
			//	relative sub-path segment
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
					$relativeProblem=$link;
				}else{
					$newUrl=$tempUrlOb['scheme'].'://'.join('/',$urlArr).'/'.join('/',$linkArr);
				}
			
			// must be a normal relative url
			}else{
				if($url[-1] !== '/'){$link='/'.$link;}
				$newUrl=$url.$link;
				$newHomeUrl=$this->homeUrl;
			}
			
			// remove any trailing dots
			$newUrl=preg_replace('/\.$/','',$newUrl);
			
			// push the new url onto the 
			$resultOb->links[]=$newUrl;
			
			
			//  if follow is turned off, or if the url has already been crawled.
			if(!$noFollow && !array_filter($this->results->getArrayCopy(),function($r) use ($newUrl){return $r->href === $newUrl;})){
				// add a new crawl
				$this->crawlArr[]=new PotentialCrawl($newUrl,[
					'parentResultIndex'=>$nextResultIndex,		 // parent result was the result index from this round.
					'problem'=>$relativeProblem,				 // This is here for the case that a problem resolving a releative sub-path was encountered
					'noFollow'=>($newHomeUrl !== $this->homeUrl) // only follow if it is in the original domain or if it is 1 step away from the original domain
				]);
			}
		}
		
		/// return the result object
		return $resultOb;
	}
	
	// get a normalized scheme and host from a url
	protected function SchemeAndHost(string $url) : string{
		$pu=parse_url($url);
	
		return strtolower($pu['scheme']).'://'.strtolower($pu['host']);
	}
	
	
	
}

class PotentialCrawl
{
	public string $href;
	public int $parentResultIndex;
	public bool $noFollow;
	public int $count300;
	public function __construct($url, $ob=[]){
		$this->href = $url;
		$this->parentResultIndex = $ob['parentResultIndex'] ?? -1;
		$this->noFollow = $ob['noFollow'] ?? false;
		$this->count300 = $ob['count300'] ?? 0;
	}
	
	public function ToJson() : string{
		return json_encode([
			'href'=>$this->href,
			'parentResultIndex'=>$this->parentResultIndex,
			'noFollow'=>$this->noFollow,
			'count300'=>$this->count300
		]);
	}
}


class ArrayOfPotentialCrawls extends \ArrayObject{
	// this ensures that when you add to the array, the type is PotentialCrawl
	public function offsetSet($key, $val){
		if($val instanceof PotentialCrawl){
			return parent::offsetSet($key,$val);
		}
		throw new \InvalidArgumentException('Value must be a potential crawl.');
		
	}
	
	// These functions are here because PHP doesn't allow you to extend the native array.
	public function Unshift(PotentialCrawl ...$crawl) : int{
		$arr=parent::getArrayCopy();
		$ret=array_unshift($arr,...$crawl);
		parent::exchangeArray($arr);
		return $ret;
	}
	public function Shift() : PotentialCrawl{
		$arr=parent::getArrayCopy();
		$mem=array_shift($arr);
		parent::exchangeArray($arr);
		return $mem;
	}
}


class CrawlResult{
	
	public string $href;
	public bool $responseSuccess;
	public array $headers;
	public array $links;
	public string $problem;
	public string $responseCode;
	public int $parentResultIndex;
	public function __construct($ob){
		$this->href = $ob['href'] ?? '';
		$this->responseSuccess = $ob['responseSuccess'] ?? false;
		$this->headers = $ob['headers'] ?? [];
		$this->links = $ob['links'] ?? [];
		$this->problem = $ob['problems'] ?? '';
		$this->responseCode = $ob['responseCode'] ?? '000';
		$this->parentResultIndex = $ob['parentResultIndex'] ?? -1;
	}
	
	public function ToJson() : string{
		return json_encode([
			'href'=>$this->href,
			'resonseSuccess'=>$this->responseSuccess,
			'responseCode'=>$this->responseCode,
			'headers'=>$this->headers,
			'links'=>$this->links,
			'problem'=>$this->problem,
			'parentResultIndex'=>$this->parentResultIndex
		]);
	}
	
}
class ArrayOfCrawlResults extends \ArrayObject{
	// this ensures that when you add to the array, the type is CrawlResult
	public function offsetSet($key, $val){
		if($val instanceof CrawlResult){
			return parent::offsetSet($key,$val);
		}
		throw new \InvalidArgumentException('Value must be a crawl result.');
		
	}
}

?>