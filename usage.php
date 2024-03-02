<?php 
require_once("crawlerClass.php");

$crawler=new WAAWebCrawler\Crawler($sqlConn, $crawlPk);
WAAWebCrawler\Crawler::$maxCrawls=4;
WAAWebCrawler\Crawler::$userAgent="YourUA";
WAAWebCrawler\Crawler::$logLocation="crawlerDebug.com";

$results=$crawler->Crawl("https://yourWebsite.com");
?>