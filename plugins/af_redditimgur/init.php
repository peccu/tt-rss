<?php
class Af_RedditImgur extends Plugin {
	private $host;

	function about() {
		return array(1.0,
			"Inline image links in Reddit RSS feeds",
			"fox");
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
	}

	function hook_article_filter($article) {

		if (strpos($article["link"], "reddit.com/r/") !== FALSE) {
				$doc = new DOMDocument();
				@$doc->loadHTML($article["content"]);

				if ($doc) {
					$xpath = new DOMXPath($doc);
					$entries = $xpath->query('(//a[@href]|//img[@src])');

					$found = false;

					foreach ($entries as $entry) {
						if ($entry->hasAttribute("href")) {

							$matches = array();

							if (preg_match("/https?:\/\/gfycat.com\/([a-z]+)$/i", $entry->getAttribute("href"), $matches)) {

								$tmp = fetch_file_contents($entry->getAttribute("href"));

								if ($tmp) {
									$tmpdoc = new DOMDocument();
									@$tmpdoc->loadHTML($tmp);

									if ($tmpdoc) {
										$tmpxpath = new DOMXPath($tmpdoc);
										$source_meta = $tmpxpath->query("//meta[@property='og:video']")->item(0);

										if ($source_meta) {
											$source_stream = $source_meta->getAttribute("content");

											if ($source_stream) {
												$this->handle_as_video($doc, $entry, $source_stream);
												$found = 1;
											}
										}
									}
								}

							}

							if (preg_match("/\.(gifv)$/i", $entry->getAttribute("href"))) {

								/*$video = $doc->createElement('video');
								$video->setAttribute("autoplay", "1");
								$video->setAttribute("loop", "1");

								$source = $doc->createElement('source');
								$source->setAttribute("src", str_replace(".gifv", ".mp4", $entry->getAttribute("href")));
								$source->setAttribute("type", "video/mp4");

								$video->appendChild($source);

								$br = $doc->createElement('br');
								$entry->parentNode->insertBefore($video, $entry);
								$entry->parentNode->insertBefore($br, $entry);

								$img = $doc->createElement('img');
								$img->setAttribute("src",
									"data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs%3D");

								$entry->parentNode->insertBefore($img, $entry);*/

								$source_stream = str_replace(".gifv", ".mp4", $entry->getAttribute("href"));
								$this->handle_as_video($doc, $entry, $source_stream);

								$found = true;
							}

							$matches = array();
							if (preg_match("/\/\/www\.youtube\.com\/v\/([\w-]+)/", $entry->getAttribute("href"), $matches) ||
								preg_match("/\/\/www\.youtube\.com\/watch?v=([\w-]+)/", $entry->getAttribute("href"), $matches) ||
								preg_match("/\/\/youtu.be\/([\w-]+)/", $entry->getAttribute("href"), $matches)) {

								$vid_id = $matches[1];

								$iframe = $doc->createElement("iframe");
								$iframe->setAttribute("class", "youtube-player");
								$iframe->setAttribute("type", "text/html");
								$iframe->setAttribute("width", "640");
								$iframe->setAttribute("height", "385");
								$iframe->setAttribute("src", "https://www.youtube.com/embed/$vid_id");
								$iframe->setAttribute("allowfullscreen", "1");
								$iframe->setAttribute("frameborder", "0");

								$br = $doc->createElement('br');
								$entry->parentNode->insertBefore($iframe, $entry);
								$entry->parentNode->insertBefore($br, $entry);

								$found = true;
							}

							if (preg_match("/\.(jpg|jpeg|gif|png)(\?[0-9][0-9]*)?$/i", $entry->getAttribute("href"))) {
								$img = $doc->createElement('img');
								$img->setAttribute("src", $entry->getAttribute("href"));

								$br = $doc->createElement('br');
								$entry->parentNode->insertBefore($img, $entry);
								$entry->parentNode->insertBefore($br, $entry);

								$found = true;
							}

							// links to imgur pages
							$matches = array();
							if (preg_match("/^https?:\/\/(m\.)?imgur.com\/([^\.\/]+$)/", $entry->getAttribute("href"), $matches)) {

								$token = $matches[2];

								$album_content = fetch_file_contents($entry->getAttribute("href"),
									false, false, false, false, 10);

								if ($album_content && $token) {
									$adoc = new DOMDocument();
									@$adoc->loadHTML($album_content);

									if ($adoc) {
										$axpath = new DOMXPath($adoc);
										$aentries = $axpath->query('(//img[@src])');

										foreach ($aentries as $aentry) {
											if (preg_match("/\/\/i.imgur.com\/$token\./", $aentry->getAttribute("src"))) {
												$img = $doc->createElement('img');
												$img->setAttribute("src", $aentry->getAttribute("src"));

												$br = $doc->createElement('br');

												$entry->parentNode->insertBefore($img, $entry);
												$entry->parentNode->insertBefore($br, $entry);

												$found = true;

												break;
											}
										}
									}
								}
							}

							// linked albums, ffs
							if (preg_match("/^https?:\/\/imgur.com\/(a|album|gallery)\/[^\.]+$/", $entry->getAttribute("href"), $matches)) {

								$album_content = fetch_file_contents($entry->getAttribute("href"),
									false, false, false, false, 10);

								if ($album_content) {
									$adoc = new DOMDocument();
									@$adoc->loadHTML($album_content);

									if ($adoc) {
										$axpath = new DOMXPath($adoc);
										$aentries = $axpath->query("//meta[@property='og:image']");
										$urls = array();

										foreach ($aentries as $aentry) {

											if (!in_array($aentry->getAttribute("content"), $urls)) {
												$img = $doc->createElement('img');
												$img->setAttribute("src", $aentry->getAttribute("content"));
												$entry->parentNode->insertBefore($doc->createElement('br'), $entry);

												$br = $doc->createElement('br');

												$entry->parentNode->insertBefore($img, $entry);
												$entry->parentNode->insertBefore($br, $entry);

												array_push($urls, $aentry->getAttribute("content"));

												$found = true;
											}
										}
									}
								}
							}
						}

						// remove tiny thumbnails
						if ($entry->hasAttribute("src")) {
							if ($entry->parentNode && $entry->parentNode->parentNode) {
								$entry->parentNode->parentNode->removeChild($entry->parentNode);
							}
						}
					}

					$node = $doc->getElementsByTagName('body')->item(0);

					if ($node && $found) {
						$article["content"] = $doc->saveXML($node);
					}
				}
		}

		return $article;
	}

	function api_version() {
		return 2;
	}

	private function handle_as_video($doc, $entry, $source_stream) {

		$video = $doc->createElement('video');
		$video->setAttribute("autoplay", "1");
		$video->setAttribute("controls", "1");
		$video->setAttribute("loop", "1");

		$source = $doc->createElement('source');
		$source->setAttribute("src", $source_stream);
		$source->setAttribute("type", "video/mp4");

		$video->appendChild($source);

		$br = $doc->createElement('br');
		$entry->parentNode->insertBefore($video, $entry);
		$entry->parentNode->insertBefore($br, $entry);

		$img = $doc->createElement('img');
		$img->setAttribute("src",
			"data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs%3D");

		$entry->parentNode->insertBefore($img, $entry);
	}
}
?>
