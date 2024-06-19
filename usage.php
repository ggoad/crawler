<?php 
require_once("crawlerClass.php");

use WAAWebCrawler\Crawler;

Crawler::$maxCrawls=4;
Crawler::$userAgent="YourUA";
Crawler::$logLocation="crawlerDebug.txt";

$crawler=new Crawler();


// $results is of the type WAAWebCrawler\ArrayOfCrawlResults. 
$results=$crawler->Crawl("https://greggoad.com");

// To get a php array, call $results->getArrayCopy();
$arr=$results->getArrayCopy();

// echo results
foreach($arr as $a)
{
	echo "{<br>";
	foreach($a as $k=>$v)
	{
		echo '&nbsp;"'.$k.'":';
		echo json_encode($v).'<br><br>';
	}
	echo "<br>}<br><br>";
}
?>
