<?php 
require_once("crawlerClass.php");

$crawler=new WAAWebCrawler\Crawler($sqlConn, $crawlPk);
WAAWebCrawler\Crawler::$maxCrawls=4;
WAAWebCrawler\Crawler::$userAgent="YourUA";
WAAWebCrawler\Crawler::$logLocation="crawlerDebug.txt";

// $results is of the type WAAWebCrawler\ArrayOfCrawlResults. 
// To get a php array, call $results->getArrayCopy();
$results=$crawler->Crawl("https://yourWebsite.com");
?>