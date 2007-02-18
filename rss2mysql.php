<?php
/** 
 * RSS items to MySQL database script.
 * 
 * Reads an RSS feed, extracts the <item></item> RSS items and stores them in a
 * database.
 */

/*
RSS2MySQL is Public Domain.

I grant anyone the right to use this work for any purpose, without any
conditions.

NO WARRANTY

BECAUSE THE PROGRAM IS LICENSED FREE OF CHARGE, THERE IS NO WARRANTY FOR
THE PROGRAM, TO THE EXTENT PERMITTED BY APPLICABLE LAW.  THE AUTHOR
PROVIDES THE PROGRAM "AS IS" WITHOUT WARRANTY OF ANY KIND, EITHER EXPRESSED
OR IMPLIED, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF
MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE.  THE ENTIRE RISK AS
TO THE QUALITY AND PERFORMANCE OF THE PROGRAM IS WITH YOU.  SHOULD THE
PROGRAM PROVE DEFECTIVE, YOU ASSUME THE COST OF ALL NECESSARY SERVICING,
REPAIR OR CORRECTION.
*/

/* Database table structure:

CREATE TABLE `items` (
  `id` int(10) unsigned NOT NULL auto_increment,
  `title` varchar(100) NOT NULL default '',
  `link` varchar(100) NOT NULL default '',
  `description` text NOT NULL,
  `dc_creator` varchar(100) NOT NULL default '',
  `dc_date` datetime NOT NULL default '0000-00-00 00:00:00',
  `dc_subject` varchar(100) NOT NULL default '',
  PRIMARY KEY  (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

*/


/////////////////////////////////////////////////////////////////////////////
// Settings 
//
$file = "slashdot.rss";

$db_hostname = "localhost";
$db_username = "todsah";
$db_password = "";
$db_database = "rss";
/////////////////////////////////////////////////////////////////////////////

error_reporting(E_ALL);

/**
 * Database wrapping object for storing RSS items
 */
class RSSDB {

	/**
	 * Set up the database connection.
	 * @param $db_hostname string with the hostname to which to connect.
	 * @param $db_username string with the username with which to connect.
	 * @param $db_password string with the password with which to connect.
	 * @param $db_database string with the name of the database to use.
	 */
	function RSSDB($db_hostname, $db_username, $db_password, $db_database) {
		$this->db_hostname = $db_hostname;
		$this->db_username = $db_username;
		$this->db_password = $db_password;
		$this->db_database = $db_database;

		if (!$this->checkExtension()) {
			print("Couldn't load the MySQL connector, check your PHP installation. Aborting.");
			exit(-1);
		}
		if (!$this->getConnection()) {
			print("Couldn't connect to the database, aborting.");
			exit(-1);
		}
	}
	
	/**
	 * Check to see if the MySQL extension is loaded. If not, try to load it.
	 */
	function checkExtension() {
		if (!extension_loaded("mysql")) {
			if (!@dl("mysql.so")) {
				return(false);
			}
		}
		return(true);
	}

	/**
	 * Try to establish a connection to the database server. 
	 * @return true if successful, false otherwise.
	 * @return Modifies $this->dbConn
	 */
	function getConnection() {
		$this->dbConn = mysql_connect ($this->db_hostname, $this->db_username, $this->db_password);
		if (!$this->dbConn) {
			return(false);
		}
		if (!mysql_select_db($this->db_database, $this->dbConn)) { 
			return(false);
		}
		return(true);
	}
	
	/**
	 * Perform a query on the database connection
	 * @param qry String containing the query to execute. Remember to escape properly!
	 * @return Doesn't return on qry error.
	 */
	function query($qry) {
		if (!mysql_query($qry, $this->dbConn)) {
			print("Cannot execute query. Aborting.");
			exit(1);
		}
	}

	/**
	 * Remove all items stored in the database.
	 */
	function clearRssItems() {
		$this->query("DELETE FROM items");
	}

	/**
	 * Store a single item in the database.
	 * @param Lots, see definition. Everything is a string, exceptions:
	 * @param $dc_date integer representing the date/time as a unix timestamp.
	 */
	function saveRssItem($title, $link, $description, $dc_creator, $dc_date, $dc_subject) {
		$qry = "
			INSERT INTO items 
				(
				title, 
				link, 
				description, 
				dc_creator, 
				dc_date, 
				dc_subject) 
			VALUES
				(
				'".mysql_escape_string($title)."',
				'".mysql_escape_string($link)."',
				'".mysql_escape_string($description)."',
				'".mysql_escape_string($dc_creator)."',
				FROM_UNIXTIME(".mysql_escape_string($dc_date)."),
				'".mysql_escape_string($dc_subject)."'
				);
		";

		$this->query($qry);
	}

}

/**
 * Quick'n'Dirty RSS reader and parser. Simply reads in the items in a feed. 
 */
class RSSParser {

	var $saveItems = array(
		"title"       => "string",
		"link"        => "string",
		"description" => "string",
		"dc:creator"  => "string",
		"dc:date"     => "date",
		"dc:subject"  => "string",
	);

	/**
	 * Read and parse a RSS feed which $url points at (may be an URL or local file)
	 * @param $url string containing the URL to the RSS feed. May be an URL or local file
	 */
	function RSSParser($url) {
		$this->read($url);
		$this->readItems();

	}
	
	/**
	 * Construct a new empty RSS item
	 * @return a new empty assoc. array representing an rss item 
	 */
	function newItem() {
		$retItem = array();

		foreach(array_keys($this->saveItems) as $key) {
			$retItem[$key] = "";
		}

		return($retItem);
	}

	/**
	 * Read a RSS file/url and make a big string of it so it can be parsed.
	 * @param $url string containing the URL to the RSS feed. May be an URL or local file
	 */
	function read($url) {
		$this->rssData = implode("", file($url));
	}

	/**
	 * Read <item> segments from the RSS file and stuff them in an array.
	 */
	function readItems() {
		if (!isset($this->rssData)) {
			return(-1);
		}

		$this->rssItems = array();

		$parser = xml_parser_create();

		xml_parser_set_option($parser,XML_OPTION_CASE_FOLDING,0);
		xml_parser_set_option($parser,XML_OPTION_SKIP_WHITE,1);

		xml_parse_into_struct($parser,$this->rssData,$values,$tags);
		xml_parser_free($parser);

		// Loop through all the elements in the RSS XML file.  If an <item> tag
		// is found, it's children will be added to a array until the closing
		// tag is found. Then the array is added to a list of items
		// ($this->rssItems).
		for ($i=1; $i < count($values); $i++) {

			$tagName = "";
			$tagType = "";
			$tagValue = "";

			if (array_key_exists("tag", $values[$i])) {
				$tagName = $values[$i]["tag"];
			}
			if (array_key_exists("type", $values[$i])) {
				$tagType = $values[$i]["type"];
			}
			if (array_key_exists("value", $values[$i])) {
				$tagValue = $values[$i]["value"];
			}

			if ($values[$i]["tag"] == "item" && $values[$i]["type"] == "open") {
				// Looks like we found an <item> tag. Create a new array to
				// store it's children values as they will be found on the next
				// iteration of the loop.
				$rssItem = $this->newItem();
			}
			if ($values[$i]["tag"] == "item" && $values[$i]["type"] == "close" && isset($rssItem)) {
				// </item> tag closed. Store the read item information.
				$this->rssItems[] = $rssItem;
				unset($rssItem); // No item information will be saved when this doesn't exist.
			}

			if (array_key_exists($tagName, $this->saveItems) && isset($rssItem)) {
				// Found a tag that we want to store and that's part of an
				// <item>. Save it.
				switch($this->saveItems[$tagName]) {
					case "string":
						$rssItem[$tagName] = $tagValue;
						break;
					case "date":
						$rssItem[$tagName] = strtotime($tagValue);
						break;
					default:
						print("Don't know how to handle type ".$this->saveItems[$tagName].". Aborting.");
						exit(1);
						break;
				}
			}
		}

	}
}

$db = new RSSDB($db_hostname, $db_username, $db_password, $db_database);
$parser = new RSSParser($file);

$db->clearRssItems(); // Delete old RSS items.

foreach($parser->rssItems as $rssItem) {
	$db->saveRssItem(
		$rssItem["title"],
		$rssItem["link"],
		$rssItem["description"],
		$rssItem["dc:creator"],
		$rssItem["dc:date"],
		$rssItem["dc:subject"]
	);
}

?>
