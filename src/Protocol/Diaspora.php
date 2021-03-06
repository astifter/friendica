<?php
/**
 * @file src/Protocol/diaspora.php
 * @brief The implementation of the diaspora protocol
 *
 * The new protocol is described here: http://diaspora.github.io/diaspora_federation/index.html
 * This implementation here interprets the old and the new protocol and sends the new one.
 * In the future we will remove most stuff from "validPosting" and interpret only the new protocol.
 */

namespace Friendica\Protocol;

use Friendica\Content\Text\BBCode;
use Friendica\Content\Text\Markdown;
use Friendica\Core\Cache;
use Friendica\Core\Config;
use Friendica\Core\L10n;
use Friendica\Core\PConfig;
use Friendica\Core\Protocol;
use Friendica\Core\System;
use Friendica\Core\Worker;
use Friendica\Database\DBA;
use Friendica\Model\Contact;
use Friendica\Model\Conversation;
use Friendica\Model\GContact;
use Friendica\Model\Group;
use Friendica\Model\Item;
use Friendica\Model\Profile;
use Friendica\Model\Queue;
use Friendica\Model\User;
use Friendica\Network\Probe;
use Friendica\Util\Crypto;
use Friendica\Util\DateTimeFormat;
use Friendica\Util\Map;
use Friendica\Util\Network;
use Friendica\Util\XML;
use SimpleXMLElement;

require_once 'include/dba.php';
require_once 'include/items.php';

/**
 * @brief This class contain functions to create and send Diaspora XML files
 *
 */
class Diaspora
{
	/**
	 * @brief Return a list of relay servers
	 *
	 * The list contains not only the official relays but also servers that we serve directly
	 *
	 * @param integer $item_id  The id of the item that is sent
	 * @param array   $contacts The previously fetched contacts
	 *
	 * @return array of relay servers
	 */
	public static function relayList($item_id, array $contacts = [])
	{
		$serverlist = [];

		// Fetching relay servers
		$serverdata = Config::get("system", "relay_server");

		if (!empty($serverdata)) {
			$servers = explode(",", $serverdata);
			foreach ($servers as $server) {
				$serverlist[$server] = trim($server);
			}
		}

		if (Config::get("system", "relay_directly", false)) {
			// We distribute our stuff based on the parent to ensure that the thread will be complete
			$parent = Item::selectFirst(['parent'], ['id' => $item_id]);
			if (!DBA::isResult($parent)) {
				return;
			}

			// Servers that want to get all content
			$servers = DBA::select('gserver', ['url'], ['relay-subscribe' => true, 'relay-scope' => 'all']);
			while ($server = DBA::fetch($servers)) {
				$serverlist[$server['url']] = $server['url'];
			}

			// All tags of the current post
			$condition = ['otype' => TERM_OBJ_POST, 'type' => TERM_HASHTAG, 'oid' => $parent['parent']];
			$tags = DBA::select('term', ['term'], $condition);
			$taglist = [];
			while ($tag = DBA::fetch($tags)) {
				$taglist[] = $tag['term'];
			}

			// All servers who wants content with this tag
			$tagserverlist = [];
			if (!empty($taglist)) {
				$tagserver = DBA::select('gserver-tag', ['gserver-id'], ['tag' => $taglist]);
				while ($server = DBA::fetch($tagserver)) {
					$tagserverlist[] = $server['gserver-id'];
				}
			}

			// All adresses with the given id
			if (!empty($tagserverlist)) {
				$servers = DBA::select('gserver', ['url'], ['relay-subscribe' => true, 'relay-scope' => 'tags', 'id' => $tagserverlist]);
				while ($server = DBA::fetch($servers)) {
					$serverlist[$server['url']] = $server['url'];
				}
			}
		}

		// Now we are collecting all relay contacts
		foreach ($serverlist as $server_url) {
			// We don't send messages to ourselves
			if (link_compare($server_url, System::baseUrl())) {
				continue;
			}
			$contact = self::getRelayContact($server_url);
			if (is_bool($contact)) {
				continue;
			}

			$exists = false;
			foreach ($contacts as $entry) {
				if ($entry['batch'] == $contact['batch']) {
					$exists = true;
				}
			}

			if (!$exists) {
				$contacts[] = $contact;
			}
		}

		return $contacts;
	}

	/**
	 * @brief Return a contact for a given server address or creates a dummy entry
	 *
	 * @param string $server_url The url of the server
	 * @return array with the contact
	 */
	private static function getRelayContact($server_url)
	{
		$fields = ['batch', 'id', 'name', 'network', 'archive', 'blocked'];

		// Fetch the relay contact
		$condition = ['uid' => 0, 'nurl' => normalise_link($server_url),
			'contact-type' => Contact::ACCOUNT_TYPE_RELAY];
		$contact = DBA::selectFirst('contact', $fields, $condition);

		if (DBA::isResult($contact)) {
			if ($contact['archive'] || $contact['blocked']) {
				return false;
			}
			return $contact;
		} else {
			self::setRelayContact($server_url);

			$contact = DBA::selectFirst('contact', $fields, $condition);
			if (DBA::isResult($contact)) {
				return $contact;
			}
		}

		// It should never happen that we arrive here
		return [];
	}

	/**
	 * @brief Update or insert a relay contact
	 *
	 * @param string $server_url The url of the server
	 * @param array $network_fields Optional network specific fields
	 */
	public static function setRelayContact($server_url, array $network_fields = [])
	{
		$fields = ['created' => DateTimeFormat::utcNow(),
			'name' => 'relay', 'nick' => 'relay',
			'url' => $server_url, 'network' => Protocol::DIASPORA,
			'batch' => $server_url . '/receive/public',
			'rel' => Contact::FOLLOWER, 'blocked' => false,
			'pending' => false, 'writable' => true];

		$fields = array_merge($fields, $network_fields);

		$condition = ['uid' => 0, 'nurl' => normalise_link($server_url),
			'contact-type' => Contact::ACCOUNT_TYPE_RELAY];

		if (DBA::exists('contact', $condition)) {
			unset($fields['created']);
		}

		DBA::update('contact', $fields, $condition, true);
	}

	/**
	 * @brief Return a list of participating contacts for a thread
	 *
	 * This is used for the participation feature.
	 * One of the parameters is a contact array.
	 * This is done to avoid duplicates.
	 *
	 * @param integer $thread   The id of the thread
	 * @param array   $contacts The previously fetched contacts
	 *
	 * @return array of relay servers
	 */
	public static function participantsForThread($thread, array $contacts)
	{
		$r = DBA::p("SELECT `contact`.`batch`, `contact`.`id`, `contact`.`name`, `contact`.`network`,
				`fcontact`.`batch` AS `fbatch`, `fcontact`.`network` AS `fnetwork` FROM `participation`
				INNER JOIN `contact` ON `contact`.`id` = `participation`.`cid`
				INNER JOIN `fcontact` ON `fcontact`.`id` = `participation`.`fid`
				WHERE `participation`.`iid` = ?", $thread);

		while ($contact = DBA::fetch($r)) {
			if (!empty($contact['fnetwork'])) {
				$contact['network'] = $contact['fnetwork'];
			}
			unset($contact['fnetwork']);

			if (empty($contact['batch']) && !empty($contact['fbatch'])) {
				$contact['batch'] = $contact['fbatch'];
			}
			unset($contact['fbatch']);

			$exists = false;
			foreach ($contacts as $entry) {
				if ($entry['batch'] == $contact['batch']) {
					$exists = true;
				}
			}

			if (!$exists) {
				$contacts[] = $contact;
			}
		}
		DBA::close($r);

		return $contacts;
	}

	/**
	 * @brief repairs a signature that was double encoded
	 *
	 * The function is unused at the moment. It was copied from the old implementation.
	 *
	 * @param string  $signature The signature
	 * @param string  $handle    The handle of the signature owner
	 * @param integer $level     This value is only set inside this function to avoid endless loops
	 *
	 * @return string the repaired signature
	 */
	private static function repairSignature($signature, $handle = "", $level = 1)
	{
		if ($signature == "") {
			return ($signature);
		}

		if (base64_encode(base64_decode(base64_decode($signature))) == base64_decode($signature)) {
			$signature = base64_decode($signature);
			logger("Repaired double encoded signature from Diaspora/Hubzilla handle ".$handle." - level ".$level, LOGGER_DEBUG);

			// Do a recursive call to be able to fix even multiple levels
			if ($level < 10) {
				$signature = self::repairSignature($signature, $handle, ++$level);
			}
		}

		return($signature);
	}

	/**
	 * @brief verify the envelope and return the verified data
	 *
	 * @param string $envelope The magic envelope
	 *
	 * @return string verified data
	 */
	private static function verifyMagicEnvelope($envelope)
	{
		$basedom = XML::parseString($envelope);

		if (!is_object($basedom)) {
			logger("Envelope is no XML file");
			return false;
		}

		$children = $basedom->children('http://salmon-protocol.org/ns/magic-env');

		if (sizeof($children) == 0) {
			logger("XML has no children");
			return false;
		}

		$handle = "";

		$data = base64url_decode($children->data);
		$type = $children->data->attributes()->type[0];

		$encoding = $children->encoding;

		$alg = $children->alg;

		$sig = base64url_decode($children->sig);
		$key_id = $children->sig->attributes()->key_id[0];
		if ($key_id != "") {
			$handle = base64url_decode($key_id);
		}

		$b64url_data = base64url_encode($data);
		$msg = str_replace(["\n", "\r", " ", "\t"], ["", "", "", ""], $b64url_data);

		$signable_data = $msg.".".base64url_encode($type).".".base64url_encode($encoding).".".base64url_encode($alg);

		if ($handle == '') {
			logger('No author could be decoded. Discarding. Message: ' . $envelope);
			return false;
		}

		$key = self::key($handle);
		if ($key == '') {
			logger("Couldn't get a key for handle " . $handle . ". Discarding.");
			return false;
		}

		$verify = Crypto::rsaVerify($signable_data, $sig, $key);
		if (!$verify) {
			logger('Message from ' . $handle . ' did not verify. Discarding.');
			return false;
		}

		return $data;
	}

	/**
	 * @brief encrypts data via AES
	 *
	 * @param string $key  The AES key
	 * @param string $iv   The IV (is used for CBC encoding)
	 * @param string $data The data that is to be encrypted
	 *
	 * @return string encrypted data
	 */
	private static function aesEncrypt($key, $iv, $data)
	{
		return openssl_encrypt($data, 'aes-256-cbc', str_pad($key, 32, "\0"), OPENSSL_RAW_DATA, str_pad($iv, 16, "\0"));
	}

	/**
	 * @brief decrypts data via AES
	 *
	 * @param string $key       The AES key
	 * @param string $iv        The IV (is used for CBC encoding)
	 * @param string $encrypted The encrypted data
	 *
	 * @return string decrypted data
	 */
	private static function aesDecrypt($key, $iv, $encrypted)
	{
		return openssl_decrypt($encrypted, 'aes-256-cbc', str_pad($key, 32, "\0"), OPENSSL_RAW_DATA, str_pad($iv, 16, "\0"));
	}

	/**
	 * @brief: Decodes incoming Diaspora message in the new format
	 *
	 * @param array  $importer Array of the importer user
	 * @param string $raw      raw post message
	 *
	 * @return array
	 * 'message' -> decoded Diaspora XML message
	 * 'author' -> author diaspora handle
	 * 'key' -> author public key (converted to pkcs#8)
	 */
	public static function decodeRaw(array $importer, $raw)
	{
		$data = json_decode($raw);

		// Is it a private post? Then decrypt the outer Salmon
		if (is_object($data)) {
			$encrypted_aes_key_bundle = base64_decode($data->aes_key);
			$ciphertext = base64_decode($data->encrypted_magic_envelope);

			$outer_key_bundle = '';
			@openssl_private_decrypt($encrypted_aes_key_bundle, $outer_key_bundle, $importer['prvkey']);
			$j_outer_key_bundle = json_decode($outer_key_bundle);

			if (!is_object($j_outer_key_bundle)) {
				logger('Outer Salmon did not verify. Discarding.');
				System::httpExit(400);
			}

			$outer_iv = base64_decode($j_outer_key_bundle->iv);
			$outer_key = base64_decode($j_outer_key_bundle->key);

			$xml = self::aesDecrypt($outer_key, $outer_iv, $ciphertext);
		} else {
			$xml = $raw;
		}

		$basedom = XML::parseString($xml);

		if (!is_object($basedom)) {
			logger('Received data does not seem to be an XML. Discarding. '.$xml);
			System::httpExit(400);
		}

		$base = $basedom->children(NAMESPACE_SALMON_ME);

		// Not sure if this cleaning is needed
		$data = str_replace([" ", "\t", "\r", "\n"], ["", "", "", ""], $base->data);

		// Build the signed data
		$type = $base->data[0]->attributes()->type[0];
		$encoding = $base->encoding;
		$alg = $base->alg;
		$signed_data = $data.'.'.base64url_encode($type).'.'.base64url_encode($encoding).'.'.base64url_encode($alg);

		// This is the signature
		$signature = base64url_decode($base->sig);

		// Get the senders' public key
		$key_id = $base->sig[0]->attributes()->key_id[0];
		$author_addr = base64_decode($key_id);
		if ($author_addr == '') {
			logger('No author could be decoded. Discarding. Message: ' . $xml);
			System::httpExit(400);
		}

		$key = self::key($author_addr);
		if ($key == '') {
			logger("Couldn't get a key for handle " . $author_addr . ". Discarding.");
			System::httpExit(400);
		}

		$verify = Crypto::rsaVerify($signed_data, $signature, $key);
		if (!$verify) {
			logger('Message did not verify. Discarding.');
			System::httpExit(400);
		}

		return ['message' => (string)base64url_decode($base->data),
				'author' => unxmlify($author_addr),
				'key' => (string)$key];
	}

	/**
	 * @brief: Decodes incoming Diaspora message in the deprecated format
	 *
	 * @param array  $importer Array of the importer user
	 * @param string $xml      urldecoded Diaspora salmon
	 *
	 * @return array
	 * 'message' -> decoded Diaspora XML message
	 * 'author' -> author diaspora handle
	 * 'key' -> author public key (converted to pkcs#8)
	 */
	public static function decode(array $importer, $xml)
	{
		$public = false;
		$basedom = XML::parseString($xml);

		if (!is_object($basedom)) {
			logger("XML is not parseable.");
			return false;
		}
		$children = $basedom->children('https://joindiaspora.com/protocol');

		$inner_aes_key = null;
		$inner_iv = null;

		if ($children->header) {
			$public = true;
			$author_link = str_replace('acct:', '', $children->header->author_id);
		} else {
			// This happens with posts from a relais
			if (!$importer) {
				logger("This is no private post in the old format", LOGGER_DEBUG);
				return false;
			}

			$encrypted_header = json_decode(base64_decode($children->encrypted_header));

			$encrypted_aes_key_bundle = base64_decode($encrypted_header->aes_key);
			$ciphertext = base64_decode($encrypted_header->ciphertext);

			$outer_key_bundle = '';
			openssl_private_decrypt($encrypted_aes_key_bundle, $outer_key_bundle, $importer['prvkey']);

			$j_outer_key_bundle = json_decode($outer_key_bundle);

			$outer_iv = base64_decode($j_outer_key_bundle->iv);
			$outer_key = base64_decode($j_outer_key_bundle->key);

			$decrypted = self::aesDecrypt($outer_key, $outer_iv, $ciphertext);

			logger('decrypted: '.$decrypted, LOGGER_DEBUG);
			$idom = XML::parseString($decrypted);

			$inner_iv = base64_decode($idom->iv);
			$inner_aes_key = base64_decode($idom->aes_key);

			$author_link = str_replace('acct:', '', $idom->author_id);
		}

		$dom = $basedom->children(NAMESPACE_SALMON_ME);

		// figure out where in the DOM tree our data is hiding

		$base = null;
		if ($dom->provenance->data) {
			$base = $dom->provenance;
		} elseif ($dom->env->data) {
			$base = $dom->env;
		} elseif ($dom->data) {
			$base = $dom;
		}

		if (!$base) {
			logger('unable to locate salmon data in xml');
			System::httpExit(400);
		}


		// Stash the signature away for now. We have to find their key or it won't be good for anything.
		$signature = base64url_decode($base->sig);

		// unpack the  data

		// strip whitespace so our data element will return to one big base64 blob
		$data = str_replace([" ", "\t", "\r", "\n"], ["", "", "", ""], $base->data);


		// stash away some other stuff for later

		$type = $base->data[0]->attributes()->type[0];
		$keyhash = $base->sig[0]->attributes()->keyhash[0];
		$encoding = $base->encoding;
		$alg = $base->alg;


		$signed_data = $data.'.'.base64url_encode($type).'.'.base64url_encode($encoding).'.'.base64url_encode($alg);


		// decode the data
		$data = base64url_decode($data);


		if ($public) {
			$inner_decrypted = $data;
		} else {
			// Decode the encrypted blob
			$inner_encrypted = base64_decode($data);
			$inner_decrypted = self::aesDecrypt($inner_aes_key, $inner_iv, $inner_encrypted);
		}

		if (!$author_link) {
			logger('Could not retrieve author URI.');
			System::httpExit(400);
		}
		// Once we have the author URI, go to the web and try to find their public key
		// (first this will look it up locally if it is in the fcontact cache)
		// This will also convert diaspora public key from pkcs#1 to pkcs#8

		logger('Fetching key for '.$author_link);
		$key = self::key($author_link);

		if (!$key) {
			logger('Could not retrieve author key.');
			System::httpExit(400);
		}

		$verify = Crypto::rsaVerify($signed_data, $signature, $key);

		if (!$verify) {
			logger('Message did not verify. Discarding.');
			System::httpExit(400);
		}

		logger('Message verified.');

		return ['message' => (string)$inner_decrypted,
				'author' => unxmlify($author_link),
				'key' => (string)$key];
	}


	/**
	 * @brief Dispatches public messages and find the fitting receivers
	 *
	 * @param array $msg The post that will be dispatched
	 *
	 * @return int The message id of the generated message, "true" or "false" if there was an error
	 */
	public static function dispatchPublic($msg)
	{
		$enabled = intval(Config::get("system", "diaspora_enabled"));
		if (!$enabled) {
			logger("diaspora is disabled");
			return false;
		}

		if (!($fields = self::validPosting($msg))) {
			logger("Invalid posting");
			return false;
		}

		$importer = ["uid" => 0, "page-flags" => Contact::PAGE_FREELOVE];
		$success = self::dispatch($importer, $msg, $fields);

		return $success;
	}

	/**
	 * @brief Dispatches the different message types to the different functions
	 *
	 * @param array  $importer Array of the importer user
	 * @param array  $msg      The post that will be dispatched
	 * @param object $fields   SimpleXML object that contains the message
	 *
	 * @return int The message id of the generated message, "true" or "false" if there was an error
	 */
	public static function dispatch(array $importer, $msg, $fields = null)
	{
		// The sender is the handle of the contact that sent the message.
		// This will often be different with relayed messages (for example "like" and "comment")
		$sender = $msg["author"];

		// This is only needed for private postings since this is already done for public ones before
		if (is_null($fields)) {
			$private = true;
			if (!($fields = self::validPosting($msg))) {
				logger("Invalid posting");
				return false;
			}
		} else {
			$private = false;
		}

		$type = $fields->getName();

		logger("Received message type ".$type." from ".$sender." for user ".$importer["uid"], LOGGER_DEBUG);

		switch ($type) {
			case "account_migration":
				if (!$private) {
					logger('Message with type ' . $type . ' is not private, quitting.');
					return false;
				}
				return self::receiveAccountMigration($importer, $fields);

			case "account_deletion":
				return self::receiveAccountDeletion($fields);

			case "comment":
				return self::receiveComment($importer, $sender, $fields, $msg["message"]);

			case "contact":
				if (!$private) {
					logger('Message with type ' . $type . ' is not private, quitting.');
					return false;
				}
				return self::receiveContactRequest($importer, $fields);

			case "conversation":
				if (!$private) {
					logger('Message with type ' . $type . ' is not private, quitting.');
					return false;
				}
				return self::receiveConversation($importer, $msg, $fields);

			case "like":
				return self::receiveLike($importer, $sender, $fields);

			case "message":
				if (!$private) {
					logger('Message with type ' . $type . ' is not private, quitting.');
					return false;
				}
				return self::receiveMessage($importer, $fields);

			case "participation":
				if (!$private) {
					logger('Message with type ' . $type . ' is not private, quitting.');
					return false;
				}
				return self::receiveParticipation($importer, $fields);

			case "photo": // Not implemented
				return self::receivePhoto($importer, $fields);

			case "poll_participation": // Not implemented
				return self::receivePollParticipation($importer, $fields);

			case "profile":
				if (!$private) {
					logger('Message with type ' . $type . ' is not private, quitting.');
					return false;
				}
				return self::receiveProfile($importer, $fields);

			case "reshare":
				return self::receiveReshare($importer, $fields, $msg["message"]);

			case "retraction":
				return self::receiveRetraction($importer, $sender, $fields);

			case "status_message":
				return self::receiveStatusMessage($importer, $fields, $msg["message"]);

			default:
				logger("Unknown message type ".$type);
				return false;
		}

		return true;
	}

	/**
	 * @brief Checks if a posting is valid and fetches the data fields.
	 *
	 * This function does not only check the signature.
	 * It also does the conversion between the old and the new diaspora format.
	 *
	 * @param array $msg Array with the XML, the sender handle and the sender signature
	 *
	 * @return bool|array If the posting is valid then an array with an SimpleXML object is returned
	 */
	private static function validPosting($msg)
	{
		$data = XML::parseString($msg["message"]);

		if (!is_object($data)) {
			logger("No valid XML ".$msg["message"], LOGGER_DEBUG);
			return false;
		}

		// Is this the new or the old version?
		if ($data->getName() == "XML") {
			$oldXML = true;
			foreach ($data->post->children() as $child) {
				$element = $child;
			}
		} else {
			$oldXML = false;
			$element = $data;
		}

		$type = $element->getName();
		$orig_type = $type;

		logger("Got message type ".$type.": ".$msg["message"], LOGGER_DATA);

		// All retractions are handled identically from now on.
		// In the new version there will only be "retraction".
		if (in_array($type, ["signed_retraction", "relayable_retraction"]))
			$type = "retraction";

		if ($type == "request") {
			$type = "contact";
		}

		$fields = new SimpleXMLElement("<".$type."/>");

		$signed_data = "";
		$author_signature = null;
		$parent_author_signature = null;

		foreach ($element->children() as $fieldname => $entry) {
			if ($oldXML) {
				// Translation for the old XML structure
				if ($fieldname == "diaspora_handle") {
					$fieldname = "author";
				}
				if ($fieldname == "participant_handles") {
					$fieldname = "participants";
				}
				if (in_array($type, ["like", "participation"])) {
					if ($fieldname == "target_type") {
						$fieldname = "parent_type";
					}
				}
				if ($fieldname == "sender_handle") {
					$fieldname = "author";
				}
				if ($fieldname == "recipient_handle") {
					$fieldname = "recipient";
				}
				if ($fieldname == "root_diaspora_id") {
					$fieldname = "root_author";
				}
				if ($type == "status_message") {
					if ($fieldname == "raw_message") {
						$fieldname = "text";
					}
				}
				if ($type == "retraction") {
					if ($fieldname == "post_guid") {
						$fieldname = "target_guid";
					}
					if ($fieldname == "type") {
						$fieldname = "target_type";
					}
				}
			}

			if (($fieldname == "author_signature") && ($entry != "")) {
				$author_signature = base64_decode($entry);
			} elseif (($fieldname == "parent_author_signature") && ($entry != "")) {
				$parent_author_signature = base64_decode($entry);
			} elseif (!in_array($fieldname, ["author_signature", "parent_author_signature", "target_author_signature"])) {
				if ($signed_data != "") {
					$signed_data .= ";";
				}

				$signed_data .= $entry;
			}
			if (!in_array($fieldname, ["parent_author_signature", "target_author_signature"])
				|| ($orig_type == "relayable_retraction")
			) {
				XML::copy($entry, $fields, $fieldname);
			}
		}

		// This is something that shouldn't happen at all.
		if (in_array($type, ["status_message", "reshare", "profile"])) {
			if ($msg["author"] != $fields->author) {
				logger("Message handle is not the same as envelope sender. Quitting this message.");
				return false;
			}
		}

		// Only some message types have signatures. So we quit here for the other types.
		if (!in_array($type, ["comment", "like"])) {
			return $fields;
		}
		// No author_signature? This is a must, so we quit.
		if (!isset($author_signature)) {
			logger("No author signature for type ".$type." - Message: ".$msg["message"], LOGGER_DEBUG);
			return false;
		}

		if (isset($parent_author_signature)) {
			$key = self::key($msg["author"]);
			if (empty($key)) {
				logger("No key found for parent author ".$msg["author"], LOGGER_DEBUG);
				return false;
			}

			if (!Crypto::rsaVerify($signed_data, $parent_author_signature, $key, "sha256")) {
				logger("No valid parent author signature for parent author ".$msg["author"]. " in type ".$type." - signed data: ".$signed_data." - Message: ".$msg["message"]." - Signature ".$parent_author_signature, LOGGER_DEBUG);
				return false;
			}
		}

		$key = self::key($fields->author);
		if (empty($key)) {
			logger("No key found for author ".$fields->author, LOGGER_DEBUG);
			return false;
		}

		if (!Crypto::rsaVerify($signed_data, $author_signature, $key, "sha256")) {
			logger("No valid author signature for author ".$fields->author. " in type ".$type." - signed data: ".$signed_data." - Message: ".$msg["message"]." - Signature ".$author_signature, LOGGER_DEBUG);
			return false;
		} else {
			return $fields;
		}
	}

	/**
	 * @brief Fetches the public key for a given handle
	 *
	 * @param string $handle The handle
	 *
	 * @return string The public key
	 */
	private static function key($handle)
	{
		$handle = strval($handle);

		logger("Fetching diaspora key for: ".$handle);

		$r = self::personByHandle($handle);
		if ($r) {
			return $r["pubkey"];
		}

		return "";
	}

	/**
	 * @brief Fetches data for a given handle
	 *
	 * @param string $handle The handle
	 *
	 * @return array the queried data
	 */
	public static function personByHandle($handle)
	{
		$update = false;

		$person = DBA::selectFirst('fcontact', [], ['network' => Protocol::DIASPORA, 'addr' => $handle]);
		if (DBA::isResult($person)) {
			logger("In cache " . print_r($person, true), LOGGER_DEBUG);

			// update record occasionally so it doesn't get stale
			$d = strtotime($person["updated"]." +00:00");
			if ($d < strtotime("now - 14 days")) {
				$update = true;
			}

			if ($person["guid"] == "") {
				$update = true;
			}
		}

		if (!DBA::isResult($person) || $update) {
			logger("create or refresh", LOGGER_DEBUG);
			$r = Probe::uri($handle, Protocol::DIASPORA);

			// Note that Friendica contacts will return a "Diaspora person"
			// if Diaspora connectivity is enabled on their server
			if ($r && ($r["network"] === Protocol::DIASPORA)) {
				self::updateFContact($r);

				// Fetch the updated or added contact
				$person = DBA::selectFirst('fcontact', [], ['network' => Protocol::DIASPORA, 'addr' => $handle]);
				if (!DBA::isResult($person)) {
					$person = $r;
				}
			}
		}

		return $person;
	}

	/**
	 * @brief Updates the fcontact table
	 *
	 * @param array $arr The fcontact data
	 */
	private static function updateFContact($arr)
	{
		$fields = ['name' => $arr["name"], 'photo' => $arr["photo"],
			'request' => $arr["request"], 'nick' => $arr["nick"],
			'addr' => strtolower($arr["addr"]), 'guid' => $arr["guid"],
			'batch' => $arr["batch"], 'notify' => $arr["notify"],
			'poll' => $arr["poll"], 'confirm' => $arr["confirm"],
			'alias' => $arr["alias"], 'pubkey' => $arr["pubkey"],
			'updated' => DateTimeFormat::utcNow()];

		$condition = ['url' => $arr["url"], 'network' => $arr["network"]];

		DBA::update('fcontact', $fields, $condition, true);
	}

	/**
	 * @brief get a handle (user@domain.tld) from a given contact id
	 *
	 * @param int $contact_id  The id in the contact table
	 * @param int $pcontact_id The id in the contact table (Used for the public contact)
	 *
	 * @return string the handle
	 */
	private static function handleFromContact($contact_id, $pcontact_id = 0)
	{
		$handle = false;

		logger("contact id is ".$contact_id." - pcontact id is ".$pcontact_id, LOGGER_DEBUG);

		if ($pcontact_id != 0) {
			$contact = DBA::selectFirst('contact', ['addr'], ['id' => $pcontact_id]);

			if (DBA::isResult($contact) && !empty($contact["addr"])) {
				return strtolower($contact["addr"]);
			}
		}

		$r = q(
			"SELECT `network`, `addr`, `self`, `url`, `nick` FROM `contact` WHERE `id` = %d",
			intval($contact_id)
		);

		if (DBA::isResult($r)) {
			$contact = $r[0];

			logger("contact 'self' = ".$contact['self']." 'url' = ".$contact['url'], LOGGER_DEBUG);

			if ($contact['addr'] != "") {
				$handle = $contact['addr'];
			} else {
				$baseurl_start = strpos($contact['url'], '://') + 3;
				// allows installations in a subdirectory--not sure how Diaspora will handle
				$baseurl_length = strpos($contact['url'], '/profile') - $baseurl_start;
				$baseurl = substr($contact['url'], $baseurl_start, $baseurl_length);
				$handle = $contact['nick'].'@'.$baseurl;
			}
		}

		return strtolower($handle);
	}

	/**
	 * @brief get a url (scheme://domain.tld/u/user) from a given Diaspora*
	 * fcontact guid
	 *
	 * @param mixed $fcontact_guid Hexadecimal string guid
	 *
	 * @return string the contact url or null
	 */
	public static function urlFromContactGuid($fcontact_guid)
	{
		logger("fcontact guid is ".$fcontact_guid, LOGGER_DEBUG);

		$r = q(
			"SELECT `url` FROM `fcontact` WHERE `url` != '' AND `network` = '%s' AND `guid` = '%s'",
			DBA::escape(Protocol::DIASPORA),
			DBA::escape($fcontact_guid)
		);

		if (DBA::isResult($r)) {
			return $r[0]['url'];
		}

		return null;
	}

	/**
	 * @brief Get a contact id for a given handle
	 *
	 * @todo Move to Friendica\Model\Contact
	 *
	 * @param int    $uid    The user id
	 * @param string $handle The handle in the format user@domain.tld
	 *
	 * @return int Contact id
	 */
	private static function contactByHandle($uid, $handle)
	{
		$cid = Contact::getIdForURL($handle, $uid);
		if (!$cid) {
			$handle_parts = explode("@", $handle);
			$nurl_sql = "%%://" . $handle_parts[1] . "%%/profile/" . $handle_parts[0];
			$cid = Contact::getIdForURL($nurl_sql, $uid);
		}

		if (!$cid) {
			logger("Haven't found a contact for user " . $uid . " and handle " . $handle, LOGGER_DEBUG);
			return false;
		}

		$contact = DBA::selectFirst('contact', [], ['id' => $cid]);
		if (!DBA::isResult($contact)) {
			// This here shouldn't happen at all
			logger("Haven't found a contact for user " . $uid . " and handle " . $handle, LOGGER_DEBUG);
			return false;
		}

		return $contact;
	}

	/**
	 * @brief Check if posting is allowed for this contact
	 *
	 * @param array $importer   Array of the importer user
	 * @param array $contact    The contact that is checked
	 * @param bool  $is_comment Is the check for a comment?
	 *
	 * @return bool is the contact allowed to post?
	 */
	private static function postAllow(array $importer, array $contact, $is_comment = false)
	{
		/*
		 * Perhaps we were already sharing with this person. Now they're sharing with us.
		 * That makes us friends.
		 * Normally this should have handled by getting a request - but this could get lost
		 */
		// It is deactivated by now, due to side effects. See issue https://github.com/friendica/friendica/pull/4033
		// It is not removed by now. Possibly the code is needed?
		//if (!$is_comment && $contact["rel"] == Contact::FOLLOWER && in_array($importer["page-flags"], array(Contact::PAGE_FREELOVE))) {
		//	DBA::update(
		//		'contact',
		//		array('rel' => Contact::FRIEND, 'writable' => true),
		//		array('id' => $contact["id"], 'uid' => $contact["uid"])
		//	);
		//
		//	$contact["rel"] = Contact::FRIEND;
		//	logger("defining user ".$contact["nick"]." as friend");
		//}

		// We don't seem to like that person
		if ($contact["blocked"]) {
			// Maybe blocked, don't accept.
			return false;
			// We are following this person?
		} elseif (($contact["rel"] == Contact::SHARING) || ($contact["rel"] == Contact::FRIEND)) {
			// Yes, then it is fine.
			return true;
			// Is it a post to a community?
		} elseif (($contact["rel"] == Contact::FOLLOWER) && in_array($importer["page-flags"], [Contact::PAGE_COMMUNITY, Contact::PAGE_PRVGROUP])) {
			// That's good
			return true;
			// Is the message a global user or a comment?
		} elseif (($importer["uid"] == 0) || $is_comment) {
			// Messages for the global users and comments are always accepted
			return true;
		}

		return false;
	}

	/**
	 * @brief Fetches the contact id for a handle and checks if posting is allowed
	 *
	 * @param array  $importer   Array of the importer user
	 * @param string $handle     The checked handle in the format user@domain.tld
	 * @param bool   $is_comment Is the check for a comment?
	 *
	 * @return array The contact data
	 */
	private static function allowedContactByHandle(array $importer, $handle, $is_comment = false)
	{
		$contact = self::contactByHandle($importer["uid"], $handle);
		if (!$contact) {
			logger("A Contact for handle ".$handle." and user ".$importer["uid"]." was not found");
			// If a contact isn't found, we accept it anyway if it is a comment
			if ($is_comment && ($importer["uid"] != 0)) {
				return self::contactByHandle(0, $handle);
			} elseif ($is_comment) {
				return $importer;
			} else {
				return false;
			}
		}

		if (!self::postAllow($importer, $contact, $is_comment)) {
			logger("The handle: ".$handle." is not allowed to post to user ".$importer["uid"]);
			return false;
		}
		return $contact;
	}

	/**
	 * @brief Does the message already exists on the system?
	 *
	 * @param int    $uid  The user id
	 * @param string $guid The guid of the message
	 *
	 * @return int|bool message id if the message already was stored into the system - or false.
	 */
	private static function messageExists($uid, $guid)
	{
		$item = Item::selectFirst(['id'], ['uid' => $uid, 'guid' => $guid]);
		if (DBA::isResult($item)) {
			logger("message ".$guid." already exists for user ".$uid);
			return $item["id"];
		}

		return false;
	}

	/**
	 * @brief Checks for links to posts in a message
	 *
	 * @param array $item The item array
	 * @return void
	 */
	private static function fetchGuid(array $item)
	{
		$expression = "=diaspora://.*?/post/([0-9A-Za-z\-_@.:]{15,254}[0-9A-Za-z])=ism";
		preg_replace_callback(
			$expression,
			function ($match) use ($item) {
				self::fetchGuidSub($match, $item);
			},
			$item["body"]
		);

		preg_replace_callback(
			"&\[url=/posts/([^\[\]]*)\](.*)\[\/url\]&Usi",
			function ($match) use ($item) {
				self::fetchGuidSub($match, $item);
			},
			$item["body"]
		);
	}

	/**
	 * @brief Checks for relative /people/* links in an item body to match local
	 * contacts or prepends the remote host taken from the author link.
	 *
	 * @param string $body        The item body to replace links from
	 * @param string $author_link The author link for missing local contact fallback
	 *
	 * @return string the replaced string
	 */
	public static function replacePeopleGuid($body, $author_link)
	{
		$return = preg_replace_callback(
			"&\[url=/people/([^\[\]]*)\](.*)\[\/url\]&Usi",
			function ($match) use ($author_link) {
				// $match
				// 0 => '[url=/people/0123456789abcdef]Foo Bar[/url]'
				// 1 => '0123456789abcdef'
				// 2 => 'Foo Bar'
				$handle = self::urlFromContactGuid($match[1]);

				if ($handle) {
					$return = '@[url='.$handle.']'.$match[2].'[/url]';
				} else {
					// No local match, restoring absolute remote URL from author scheme and host
					$author_url = parse_url($author_link);
					$return = '[url='.$author_url['scheme'].'://'.$author_url['host'].'/people/'.$match[1].']'.$match[2].'[/url]';
				}

				return $return;
			},
			$body
		);

		return $return;
	}

	/**
	 * @brief sub function of "fetchGuid" which checks for links in messages
	 *
	 * @param array $match array containing a link that has to be checked for a message link
	 * @param array $item  The item array
	 * @return void
	 */
	private static function fetchGuidSub($match, $item)
	{
		if (!self::storeByGuid($match[1], $item["author-link"])) {
			self::storeByGuid($match[1], $item["owner-link"]);
		}
	}

	/**
	 * @brief Fetches an item with a given guid from a given server
	 *
	 * @param string $guid   the message guid
	 * @param string $server The server address
	 * @param int    $uid    The user id of the user
	 *
	 * @return int the message id of the stored message or false
	 */
	private static function storeByGuid($guid, $server, $uid = 0)
	{
		$serverparts = parse_url($server);

		if (empty($serverparts["host"]) || empty($serverparts["scheme"])) {
			return false;
		}

		$server = $serverparts["scheme"]."://".$serverparts["host"];

		logger("Trying to fetch item ".$guid." from ".$server, LOGGER_DEBUG);

		$msg = self::message($guid, $server);

		if (!$msg) {
			return false;
		}

		logger("Successfully fetched item ".$guid." from ".$server, LOGGER_DEBUG);

		// Now call the dispatcher
		return self::dispatchPublic($msg);
	}

	/**
	 * @brief Fetches a message from a server
	 *
	 * @param string $guid   message guid
	 * @param string $server The url of the server
	 * @param int    $level  Endless loop prevention
	 *
	 * @return array
	 *      'message' => The message XML
	 *      'author' => The author handle
	 *      'key' => The public key of the author
	 */
	private static function message($guid, $server, $level = 0)
	{
		if ($level > 5) {
			return false;
		}

		// This will work for new Diaspora servers and Friendica servers from 3.5
		$source_url = $server."/fetch/post/".urlencode($guid);

		logger("Fetch post from ".$source_url, LOGGER_DEBUG);

		$envelope = Network::fetchUrl($source_url);
		if ($envelope) {
			logger("Envelope was fetched.", LOGGER_DEBUG);
			$x = self::verifyMagicEnvelope($envelope);
			if (!$x) {
				logger("Envelope could not be verified.", LOGGER_DEBUG);
			} else {
				logger("Envelope was verified.", LOGGER_DEBUG);
			}
		} else {
			$x = false;
		}

		// This will work for older Diaspora and Friendica servers
		if (!$x) {
			$source_url = $server."/p/".urlencode($guid).".xml";
			logger("Fetch post from ".$source_url, LOGGER_DEBUG);

			$x = Network::fetchUrl($source_url);
			if (!$x) {
				return false;
			}
		}

		$source_xml = XML::parseString($x);

		if (!is_object($source_xml)) {
			return false;
		}

		if ($source_xml->post->reshare) {
			// Reshare of a reshare - old Diaspora version
			logger("Message is a reshare", LOGGER_DEBUG);
			return self::message($source_xml->post->reshare->root_guid, $server, ++$level);
		} elseif ($source_xml->getName() == "reshare") {
			// Reshare of a reshare - new Diaspora version
			logger("Message is a new reshare", LOGGER_DEBUG);
			return self::message($source_xml->root_guid, $server, ++$level);
		}

		$author = "";

		// Fetch the author - for the old and the new Diaspora version
		if ($source_xml->post->status_message && $source_xml->post->status_message->diaspora_handle) {
			$author = (string)$source_xml->post->status_message->diaspora_handle;
		} elseif ($source_xml->author && ($source_xml->getName() == "status_message")) {
			$author = (string)$source_xml->author;
		}

		// If this isn't a "status_message" then quit
		if (!$author) {
			logger("Message doesn't seem to be a status message", LOGGER_DEBUG);
			return false;
		}

		$msg = ["message" => $x, "author" => $author];

		$msg["key"] = self::key($msg["author"]);

		return $msg;
	}

	/**
	 * @brief Fetches the item record of a given guid
	 *
	 * @param int    $uid     The user id
	 * @param string $guid    message guid
	 * @param string $author  The handle of the item
	 * @param array  $contact The contact of the item owner
	 *
	 * @return array the item record
	 */
	private static function parentItem($uid, $guid, $author, array $contact)
	{
		$fields = ['id', 'parent', 'body', 'wall', 'uri', 'guid', 'private', 'origin',
			'author-name', 'author-link', 'author-avatar',
			'owner-name', 'owner-link', 'owner-avatar'];
		$condition = ['uid' => $uid, 'guid' => $guid];
		$item = Item::selectFirst($fields, $condition);

		if (!DBA::isResult($item)) {
			$person = self::personByHandle($author);
			$result = self::storeByGuid($guid, $person["url"], $uid);

			// We don't have an url for items that arrived at the public dispatcher
			if (!$result && !empty($contact["url"])) {
				$result = self::storeByGuid($guid, $contact["url"], $uid);
			}

			if ($result) {
				logger("Fetched missing item ".$guid." - result: ".$result, LOGGER_DEBUG);

				$item = Item::selectFirst($fields, $condition);
			}
		}

		if (!DBA::isResult($item)) {
			logger("parent item not found: parent: ".$guid." - user: ".$uid);
			return false;
		} else {
			logger("parent item found: parent: ".$guid." - user: ".$uid);
			return $item;
		}
	}

	/**
	 * @brief returns contact details
	 *
	 * @param array $def_contact The default contact if the person isn't found
	 * @param array $person      The record of the person
	 * @param int   $uid         The user id
	 *
	 * @return array
	 *      'cid' => contact id
	 *      'network' => network type
	 */
	private static function authorContactByUrl($def_contact, $person, $uid)
	{
		$condition = ['nurl' => normalise_link($person["url"]), 'uid' => $uid];
		$contact = DBA::selectFirst('contact', ['id', 'network'], $condition);
		if (DBA::isResult($contact)) {
			$cid = $contact["id"];
			$network = $contact["network"];
		} else {
			$cid = $def_contact["id"];
			$network = Protocol::DIASPORA;
		}

		return ["cid" => $cid, "network" => $network];
	}

	/**
	 * @brief Is the profile a hubzilla profile?
	 *
	 * @param string $url The profile link
	 *
	 * @return bool is it a hubzilla server?
	 */
	public static function isRedmatrix($url)
	{
		return(strstr($url, "/channel/"));
	}

	/**
	 * @brief Generate a post link with a given handle and message guid
	 *
	 * @param string $addr        The user handle
	 * @param string $guid        message guid
	 * @param string $parent_guid optional parent guid
	 *
	 * @return string the post link
	 */
	private static function plink($addr, $guid, $parent_guid = '')
	{
		$contact = Contact::getDetailsByAddr($addr);

		// Fallback
		if (!$contact) {
			if ($parent_guid != '') {
				return "https://" . substr($addr, strpos($addr, "@") + 1) . "/posts/" . $parent_guid . "#" . $guid;
			} else {
				return "https://" . substr($addr, strpos($addr, "@") + 1) . "/posts/" . $guid;
			}
		}

		if ($contact["network"] == Protocol::DFRN) {
			return str_replace("/profile/" . $contact["nick"] . "/", "/display/" . $guid, $contact["url"] . "/");
		}

		if (self::isRedmatrix($contact["url"])) {
			return $contact["url"] . "/?f=&mid=" . $guid;
		}

		if ($parent_guid != '') {
			return "https://" . substr($addr, strpos($addr, "@") + 1) . "/posts/" . $parent_guid . "#" . $guid;
		} else {
			return "https://" . substr($addr, strpos($addr, "@") + 1) . "/posts/" . $guid;
		}
	}

	/**
	 * @brief Receives account migration
	 *
	 * @param array  $importer Array of the importer user
	 * @param object $data     The message object
	 *
	 * @return bool Success
	 */
	private static function receiveAccountMigration(array $importer, $data)
	{
		$old_handle = notags(unxmlify($data->author));
		$new_handle = notags(unxmlify($data->profile->author));
		$signature = notags(unxmlify($data->signature));

		$contact = self::contactByHandle($importer["uid"], $old_handle);
		if (!$contact) {
			logger("cannot find contact for sender: ".$old_handle." and user ".$importer["uid"]);
			return false;
		}

		logger("Got migration for ".$old_handle.", to ".$new_handle." with user ".$importer["uid"]);

		// Check signature
		$signed_text = 'AccountMigration:'.$old_handle.':'.$new_handle;
		$key = self::key($old_handle);
		if (!Crypto::rsaVerify($signed_text, $signature, $key, "sha256")) {
			logger('No valid signature for migration.');
			return false;
		}

		// Update the profile
		self::receiveProfile($importer, $data->profile);

		// change the technical stuff in contact and gcontact
		$data = Probe::uri($new_handle);
		if ($data['network'] == Protocol::PHANTOM) {
			logger('Account for '.$new_handle." couldn't be probed.");
			return false;
		}

		$fields = ['url' => $data['url'], 'nurl' => normalise_link($data['url']),
				'name' => $data['name'], 'nick' => $data['nick'],
				'addr' => $data['addr'], 'batch' => $data['batch'],
				'notify' => $data['notify'], 'poll' => $data['poll'],
				'network' => $data['network']];

		DBA::update('contact', $fields, ['addr' => $old_handle]);

		$fields = ['url' => $data['url'], 'nurl' => normalise_link($data['url']),
				'name' => $data['name'], 'nick' => $data['nick'],
				'addr' => $data['addr'], 'connect' => $data['addr'],
				'notify' => $data['notify'], 'photo' => $data['photo'],
				'server_url' => $data['baseurl'], 'network' => $data['network']];

		DBA::update('gcontact', $fields, ['addr' => $old_handle]);

		logger('Contacts are updated.');

		return true;
	}

	/**
	 * @brief Processes an account deletion
	 *
	 * @param object $data     The message object
	 *
	 * @return bool Success
	 */
	private static function receiveAccountDeletion($data)
	{
		$author = notags(unxmlify($data->author));

		$contacts = DBA::select('contact', ['id'], ['addr' => $author]);
		while ($contact = DBA::fetch($contacts)) {
			Contact::remove($contact["id"]);
		}

		DBA::delete('gcontact', ['addr' => $author]);

		logger('Removed contacts for ' . $author);

		return true;
	}

	/**
	 * @brief Fetch the uri from our database if we already have this item (maybe from ourselves)
	 *
	 * @param string  $author    Author handle
	 * @param string  $guid      Message guid
	 * @param boolean $onlyfound Only return uri when found in the database
	 *
	 * @return string The constructed uri or the one from our database
	 */
	private static function getUriFromGuid($author, $guid, $onlyfound = false)
	{
		$item = Item::selectFirst(['uri'], ['guid' => $guid]);
		if (DBA::isResult($item)) {
			return $item["uri"];
		} elseif (!$onlyfound) {
			$contact = Contact::getDetailsByAddr($author, 0);
			if (!empty($contact['network'])) {
				$prefix = 'urn:X-' . $contact['network'] . ':';
			} else {
				// This fallback should happen most unlikely
				$prefix = 'urn:X-dspr:';
			}

			$author_parts = explode('@', $author);

			return $prefix . $author_parts[1] . ':' . $author_parts[0] . ':'. $guid;
		}

		return "";
	}

	/**
	 * @brief Fetch the guid from our database with a given uri
	 *
	 * @param string $uri Message uri
	 * @param string $uid Author handle
	 *
	 * @return string The post guid
	 */
	private static function getGuidFromUri($uri, $uid)
	{
		$item = Item::selectFirst(['guid'], ['uri' => $uri, 'uid' => $uid]);
		if (DBA::isResult($item)) {
			return $item["guid"];
		} else {
			return false;
		}
	}

	/**
	 * @brief Find the best importer for a comment, like, ...
	 *
	 * @param string $guid The guid of the item
	 *
	 * @return array|boolean the origin owner of that post - or false
	 */
	private static function importerForGuid($guid)
	{
		$item = Item::selectFirst(['uid'], ['origin' => true, 'guid' => $guid]);
		if (DBA::isResult($item)) {
			logger("Found user ".$item['uid']." as owner of item ".$guid, LOGGER_DEBUG);
			$contact = DBA::selectFirst('contact', [], ['self' => true, 'uid' => $item['uid']]);
			if (DBA::isResult($contact)) {
				return $contact;
			}
		}
		return false;
	}

	/**
	 * @brief Processes an incoming comment
	 *
	 * @param array  $importer Array of the importer user
	 * @param string $sender   The sender of the message
	 * @param object $data     The message object
	 * @param string $xml      The original XML of the message
	 *
	 * @return int The message id of the generated comment or "false" if there was an error
	 */
	private static function receiveComment(array $importer, $sender, $data, $xml)
	{
		$author = notags(unxmlify($data->author));
		$guid = notags(unxmlify($data->guid));
		$parent_guid = notags(unxmlify($data->parent_guid));
		$text = unxmlify($data->text);

		if (isset($data->created_at)) {
			$created_at = DateTimeFormat::utc(notags(unxmlify($data->created_at)));
		} else {
			$created_at = DateTimeFormat::utcNow();
		}

		if (isset($data->thread_parent_guid)) {
			$thread_parent_guid = notags(unxmlify($data->thread_parent_guid));
			$thr_uri = self::getUriFromGuid("", $thread_parent_guid, true);
		} else {
			$thr_uri = "";
		}

		$contact = self::allowedContactByHandle($importer, $sender, true);
		if (!$contact) {
			return false;
		}

		$message_id = self::messageExists($importer["uid"], $guid);
		if ($message_id) {
			return true;
		}

		$parent_item = self::parentItem($importer["uid"], $parent_guid, $author, $contact);
		if (!$parent_item) {
			return false;
		}

		$person = self::personByHandle($author);
		if (!is_array($person)) {
			logger("unable to find author details");
			return false;
		}

		// Fetch the contact id - if we know this contact
		$author_contact = self::authorContactByUrl($contact, $person, $importer["uid"]);

		$datarray = [];

		$datarray["uid"] = $importer["uid"];
		$datarray["contact-id"] = $author_contact["cid"];
		$datarray["network"]  = $author_contact["network"];

		$datarray["author-link"] = $person["url"];
		$datarray["author-id"] = Contact::getIdForURL($person["url"], 0);

		$datarray["owner-link"] = $contact["url"];
		$datarray["owner-id"] = Contact::getIdForURL($contact["url"], 0);

		$datarray["guid"] = $guid;
		$datarray["uri"] = self::getUriFromGuid($author, $guid);

		$datarray["verb"] = ACTIVITY_POST;
		$datarray["gravity"] = GRAVITY_COMMENT;

		if ($thr_uri != "") {
			$datarray["parent-uri"] = $thr_uri;
		} else {
			$datarray["parent-uri"] = $parent_item["uri"];
		}

		$datarray["object-type"] = ACTIVITY_OBJ_COMMENT;

		$datarray["protocol"] = Conversation::PARCEL_DIASPORA;
		$datarray["source"] = $xml;

		$datarray["changed"] = $datarray["created"] = $datarray["edited"] = $created_at;

		$datarray["plink"] = self::plink($author, $guid, $parent_item['guid']);

		$body = Markdown::toBBCode($text);

		$datarray["body"] = self::replacePeopleGuid($body, $person["url"]);

		self::fetchGuid($datarray);

		// If we are the origin of the parent we store the original data.
		// We notify our followers during the item storage.
		if ($parent_item["origin"]) {
			$datarray['diaspora_signed_text'] = json_encode($data);
		}

		$message_id = Item::insert($datarray);

		if ($message_id <= 0) {
			return false;
		}

		if ($message_id) {
			logger("Stored comment ".$datarray["guid"]." with message id ".$message_id, LOGGER_DEBUG);
			if ($datarray['uid'] == 0) {
				Item::distribute($message_id, json_encode($data));
			}
		}

		return true;
	}

	/**
	 * @brief processes and stores private messages
	 *
	 * @param array  $importer     Array of the importer user
	 * @param array  $contact      The contact of the message
	 * @param object $data         The message object
	 * @param array  $msg          Array of the processed message, author handle and key
	 * @param object $mesg         The private message
	 * @param array  $conversation The conversation record to which this message belongs
	 *
	 * @return bool "true" if it was successful
	 */
	private static function receiveConversationMessage(array $importer, array $contact, $data, $msg, $mesg, $conversation)
	{
		$author = notags(unxmlify($data->author));
		$guid = notags(unxmlify($data->guid));
		$subject = notags(unxmlify($data->subject));

		// "diaspora_handle" is the element name from the old version
		// "author" is the element name from the new version
		if ($mesg->author) {
			$msg_author = notags(unxmlify($mesg->author));
		} elseif ($mesg->diaspora_handle) {
			$msg_author = notags(unxmlify($mesg->diaspora_handle));
		} else {
			return false;
		}

		$msg_guid = notags(unxmlify($mesg->guid));
		$msg_conversation_guid = notags(unxmlify($mesg->conversation_guid));
		$msg_text = unxmlify($mesg->text);
		$msg_created_at = DateTimeFormat::utc(notags(unxmlify($mesg->created_at)));

		if ($msg_conversation_guid != $guid) {
			logger("message conversation guid does not belong to the current conversation.");
			return false;
		}

		$body = Markdown::toBBCode($msg_text);
		$message_uri = $msg_author.":".$msg_guid;

		$person = self::personByHandle($msg_author);

		DBA::lock('mail');

		if (DBA::exists('mail', ['guid' => $msg_guid, 'uid' => $importer["uid"]])) {
			logger("duplicate message already delivered.", LOGGER_DEBUG);
			return false;
		}

		q(
			"INSERT INTO `mail` (`uid`, `guid`, `convid`, `from-name`,`from-photo`,`from-url`,`contact-id`,`title`,`body`,`seen`,`reply`,`uri`,`parent-uri`,`created`)
			VALUES (%d, '%s', %d, '%s', '%s', '%s', %d, '%s', '%s', %d, %d, '%s','%s','%s')",
			intval($importer["uid"]),
			DBA::escape($msg_guid),
			intval($conversation["id"]),
			DBA::escape($person["name"]),
			DBA::escape($person["photo"]),
			DBA::escape($person["url"]),
			intval($contact["id"]),
			DBA::escape($subject),
			DBA::escape($body),
			0,
			0,
			DBA::escape($message_uri),
			DBA::escape($author.":".$guid),
			DBA::escape($msg_created_at)
		);

		DBA::unlock();

		DBA::update('conv', ['updated' => DateTimeFormat::utcNow()], ['id' => $conversation["id"]]);

		notification(
			[
			"type" => NOTIFY_MAIL,
			"notify_flags" => $importer["notify-flags"],
			"language" => $importer["language"],
			"to_name" => $importer["username"],
			"to_email" => $importer["email"],
			"uid" =>$importer["uid"],
			"item" => ["id" => $conversation["id"], "title" => $subject, "subject" => $subject, "body" => $body],
			"source_name" => $person["name"],
			"source_link" => $person["url"],
			"source_photo" => $person["photo"],
			"verb" => ACTIVITY_POST,
			"otype" => "mail"]
		);
		return true;
	}

	/**
	 * @brief Processes new private messages (answers to private messages are processed elsewhere)
	 *
	 * @param array  $importer Array of the importer user
	 * @param array  $msg      Array of the processed message, author handle and key
	 * @param object $data     The message object
	 *
	 * @return bool Success
	 */
	private static function receiveConversation(array $importer, $msg, $data)
	{
		$author = notags(unxmlify($data->author));
		$guid = notags(unxmlify($data->guid));
		$subject = notags(unxmlify($data->subject));
		$created_at = DateTimeFormat::utc(notags(unxmlify($data->created_at)));
		$participants = notags(unxmlify($data->participants));

		$messages = $data->message;

		if (!count($messages)) {
			logger("empty conversation");
			return false;
		}

		$contact = self::allowedContactByHandle($importer, $msg["author"], true);
		if (!$contact) {
			return false;
		}

		$conversation = DBA::selectFirst('conv', [], ['uid' => $importer["uid"], 'guid' => $guid]);
		if (!DBA::isResult($conversation)) {
			$r = q(
				"INSERT INTO `conv` (`uid`, `guid`, `creator`, `created`, `updated`, `subject`, `recips`)
				VALUES (%d, '%s', '%s', '%s', '%s', '%s', '%s')",
				intval($importer["uid"]),
				DBA::escape($guid),
				DBA::escape($author),
				DBA::escape($created_at),
				DBA::escape(DateTimeFormat::utcNow()),
				DBA::escape($subject),
				DBA::escape($participants)
			);
			if ($r) {
				$conversation = DBA::selectFirst('conv', [], ['uid' => $importer["uid"], 'guid' => $guid]);
			}
		}
		if (!$conversation) {
			logger("unable to create conversation.");
			return false;
		}

		foreach ($messages as $mesg) {
			self::receiveConversationMessage($importer, $contact, $data, $msg, $mesg, $conversation);
		}

		return true;
	}

	/**
	 * @brief Processes "like" messages
	 *
	 * @param array  $importer Array of the importer user
	 * @param string $sender   The sender of the message
	 * @param object $data     The message object
	 *
	 * @return int The message id of the generated like or "false" if there was an error
	 */
	private static function receiveLike(array $importer, $sender, $data)
	{
		$author = notags(unxmlify($data->author));
		$guid = notags(unxmlify($data->guid));
		$parent_guid = notags(unxmlify($data->parent_guid));
		$parent_type = notags(unxmlify($data->parent_type));
		$positive = notags(unxmlify($data->positive));

		// likes on comments aren't supported by Diaspora - only on posts
		// But maybe this will be supported in the future, so we will accept it.
		if (!in_array($parent_type, ["Post", "Comment"])) {
			return false;
		}

		$contact = self::allowedContactByHandle($importer, $sender, true);
		if (!$contact) {
			return false;
		}

		$message_id = self::messageExists($importer["uid"], $guid);
		if ($message_id) {
			return true;
		}

		$parent_item = self::parentItem($importer["uid"], $parent_guid, $author, $contact);
		if (!$parent_item) {
			return false;
		}

		$person = self::personByHandle($author);
		if (!is_array($person)) {
			logger("unable to find author details");
			return false;
		}

		// Fetch the contact id - if we know this contact
		$author_contact = self::authorContactByUrl($contact, $person, $importer["uid"]);

		// "positive" = "false" would be a Dislike - wich isn't currently supported by Diaspora
		// We would accept this anyhow.
		if ($positive == "true") {
			$verb = ACTIVITY_LIKE;
		} else {
			$verb = ACTIVITY_DISLIKE;
		}

		$datarray = [];

		$datarray["protocol"] = Conversation::PARCEL_DIASPORA;

		$datarray["uid"] = $importer["uid"];
		$datarray["contact-id"] = $author_contact["cid"];
		$datarray["network"]  = $author_contact["network"];

		$datarray["author-link"] = $person["url"];
		$datarray["author-id"] = Contact::getIdForURL($person["url"], 0);

		$datarray["owner-link"] = $contact["url"];
		$datarray["owner-id"] = Contact::getIdForURL($contact["url"], 0);

		$datarray["guid"] = $guid;
		$datarray["uri"] = self::getUriFromGuid($author, $guid);

		$datarray["verb"] = $verb;
		$datarray["gravity"] = GRAVITY_ACTIVITY;
		$datarray["parent-uri"] = $parent_item["uri"];

		$datarray["object-type"] = ACTIVITY_OBJ_NOTE;

		$datarray["body"] = $verb;

		// Diaspora doesn't provide a date for likes
		$datarray["changed"] = $datarray["created"] = $datarray["edited"] = DateTimeFormat::utcNow();

		// like on comments have the comment as parent. So we need to fetch the toplevel parent
		if ($parent_item["id"] != $parent_item["parent"]) {
			$toplevel = Item::selectFirst(['origin'], ['id' => $parent_item["parent"]]);
			$origin = $toplevel["origin"];
		} else {
			$origin = $parent_item["origin"];
		}

		// If we are the origin of the parent we store the original data.
		// We notify our followers during the item storage.
		if ($origin) {
			$datarray['diaspora_signed_text'] = json_encode($data);
		}

		$message_id = Item::insert($datarray);

		if ($message_id <= 0) {
			return false;
		}

		if ($message_id) {
			logger("Stored like ".$datarray["guid"]." with message id ".$message_id, LOGGER_DEBUG);
			if ($datarray['uid'] == 0) {
				Item::distribute($message_id, json_encode($data));
			}
		}

		return true;
	}

	/**
	 * @brief Processes private messages
	 *
	 * @param array  $importer Array of the importer user
	 * @param object $data     The message object
	 *
	 * @return bool Success?
	 */
	private static function receiveMessage(array $importer, $data)
	{
		$author = notags(unxmlify($data->author));
		$guid = notags(unxmlify($data->guid));
		$conversation_guid = notags(unxmlify($data->conversation_guid));
		$text = unxmlify($data->text);
		$created_at = DateTimeFormat::utc(notags(unxmlify($data->created_at)));

		$contact = self::allowedContactByHandle($importer, $author, true);
		if (!$contact) {
			return false;
		}

		$conversation = null;

		$condition = ['uid' => $importer["uid"], 'guid' => $conversation_guid];
		$conversation = DBA::selectFirst('conv', [], $condition);

		if (!DBA::isResult($conversation)) {
			logger("conversation not available.");
			return false;
		}

		$message_uri = $author.":".$guid;

		$person = self::personByHandle($author);
		if (!$person) {
			logger("unable to find author details");
			return false;
		}

		$body = Markdown::toBBCode($text);

		$body = self::replacePeopleGuid($body, $person["url"]);

		DBA::lock('mail');

		if (DBA::exists('mail', ['guid' => $guid, 'uid' => $importer["uid"]])) {
			logger("duplicate message already delivered.", LOGGER_DEBUG);
			return false;
		}

		q(
			"INSERT INTO `mail` (`uid`, `guid`, `convid`, `from-name`,`from-photo`,`from-url`,`contact-id`,`title`,`body`,`seen`,`reply`,`uri`,`parent-uri`,`created`)
				VALUES ( %d, '%s', %d, '%s', '%s', '%s', %d, '%s', '%s', %d, %d, '%s','%s','%s')",
			intval($importer["uid"]),
			DBA::escape($guid),
			intval($conversation["id"]),
			DBA::escape($person["name"]),
			DBA::escape($person["photo"]),
			DBA::escape($person["url"]),
			intval($contact["id"]),
			DBA::escape($conversation["subject"]),
			DBA::escape($body),
			0,
			1,
			DBA::escape($message_uri),
			DBA::escape($author.":".$conversation["guid"]),
			DBA::escape($created_at)
		);

		DBA::unlock();

		DBA::update('conv', ['updated' => DateTimeFormat::utcNow()], ['id' => $conversation["id"]]);
		return true;
	}

	/**
	 * @brief Processes participations - unsupported by now
	 *
	 * @param array  $importer Array of the importer user
	 * @param object $data     The message object
	 *
	 * @return bool always true
	 */
	private static function receiveParticipation(array $importer, $data)
	{
		$author = strtolower(notags(unxmlify($data->author)));
		$parent_guid = notags(unxmlify($data->parent_guid));

		$contact_id = Contact::getIdForURL($author);
		if (!$contact_id) {
			logger('Contact not found: '.$author);
			return false;
		}

		$person = self::personByHandle($author);
		if (!is_array($person)) {
			logger("Person not found: ".$author);
			return false;
		}

		$item = Item::selectFirst(['id'], ['guid' => $parent_guid, 'origin' => true, 'private' => false]);
		if (!DBA::isResult($item)) {
			logger('Item not found, no origin or private: '.$parent_guid);
			return false;
		}

		$author_parts = explode('@', $author);
		if (isset($author_parts[1])) {
			$server = $author_parts[1];
		} else {
			// Should never happen
			$server = $author;
		}

		logger('Received participation for ID: '.$item['id'].' - Contact: '.$contact_id.' - Server: '.$server, LOGGER_DEBUG);

		if (!DBA::exists('participation', ['iid' => $item['id'], 'server' => $server])) {
			DBA::insert('participation', ['iid' => $item['id'], 'cid' => $contact_id, 'fid' => $person['id'], 'server' => $server]);
		}

		// Send all existing comments and likes to the requesting server
		$comments = Item::select(['id', 'parent', 'verb', 'self'], ['parent' => $item['id']]);
		while ($comment = Item::fetch($comments)) {
			if ($comment['id'] == $comment['parent']) {
				continue;
			}
			if ($comment['verb'] == ACTIVITY_POST) {
				$cmd = $comment['self'] ? 'comment-new' : 'comment-import';
			} else {
				$cmd = $comment['self'] ? 'like' : 'comment-import';
			}
			logger("Send ".$cmd." for item ".$comment['id']." to contact ".$contact_id, LOGGER_DEBUG);
			Worker::add(PRIORITY_HIGH, 'Delivery', $cmd, $comment['id'], $contact_id);
		}
		DBA::close($comments);

		return true;
	}

	/**
	 * @brief Processes photos - unneeded
	 *
	 * @param array  $importer Array of the importer user
	 * @param object $data     The message object
	 *
	 * @return bool always true
	 */
	private static function receivePhoto(array $importer, $data)
	{
		// There doesn't seem to be a reason for this function,
		// since the photo data is transmitted in the status message as well
		return true;
	}

	/**
	 * @brief Processes poll participations - unssupported
	 *
	 * @param array  $importer Array of the importer user
	 * @param object $data     The message object
	 *
	 * @return bool always true
	 */
	private static function receivePollParticipation(array $importer, $data)
	{
		// We don't support polls by now
		return true;
	}

	/**
	 * @brief Processes incoming profile updates
	 *
	 * @param array  $importer Array of the importer user
	 * @param object $data     The message object
	 *
	 * @return bool Success
	 */
	private static function receiveProfile(array $importer, $data)
	{
		$author = strtolower(notags(unxmlify($data->author)));

		$contact = self::contactByHandle($importer["uid"], $author);
		if (!$contact) {
			return false;
		}

		$name = unxmlify($data->first_name).((strlen($data->last_name)) ? " ".unxmlify($data->last_name) : "");
		$image_url = unxmlify($data->image_url);
		$birthday = unxmlify($data->birthday);
		$gender = unxmlify($data->gender);
		$about = Markdown::toBBCode(unxmlify($data->bio));
		$location = Markdown::toBBCode(unxmlify($data->location));
		$searchable = (unxmlify($data->searchable) == "true");
		$nsfw = (unxmlify($data->nsfw) == "true");
		$tags = unxmlify($data->tag_string);

		$tags = explode("#", $tags);

		$keywords = [];
		foreach ($tags as $tag) {
			$tag = trim(strtolower($tag));
			if ($tag != "") {
				$keywords[] = $tag;
			}
		}

		$keywords = implode(", ", $keywords);

		$handle_parts = explode("@", $author);
		$nick = $handle_parts[0];

		if ($name === "") {
			$name = $handle_parts[0];
		}

		if (preg_match("|^https?://|", $image_url) === 0) {
			$image_url = "http://".$handle_parts[1].$image_url;
		}

		Contact::updateAvatar($image_url, $importer["uid"], $contact["id"]);

		// Generic birthday. We don't know the timezone. The year is irrelevant.

		$birthday = str_replace("1000", "1901", $birthday);

		if ($birthday != "") {
			$birthday = DateTimeFormat::utc($birthday, "Y-m-d");
		}

		// this is to prevent multiple birthday notifications in a single year
		// if we already have a stored birthday and the 'm-d' part hasn't changed, preserve the entry, which will preserve the notify year

		if (substr($birthday, 5) === substr($contact["bd"], 5)) {
			$birthday = $contact["bd"];
		}

		$fields = ['name' => $name, 'location' => $location,
			'name-date' => DateTimeFormat::utcNow(),
			'about' => $about, 'gender' => $gender,
			'addr' => $author, 'nick' => $nick,
			'keywords' => $keywords];

		if (!empty($birthday)) {
			$fields['bd'] = $birthday;
		}

		DBA::update('contact', $fields, ['id' => $contact['id']]);

		$gcontact = ["url" => $contact["url"], "network" => Protocol::DIASPORA, "generation" => 2,
					"photo" => $image_url, "name" => $name, "location" => $location,
					"about" => $about, "birthday" => $birthday, "gender" => $gender,
					"addr" => $author, "nick" => $nick, "keywords" => $keywords,
					"hide" => !$searchable, "nsfw" => $nsfw];

		$gcid = GContact::update($gcontact);

		GContact::link($gcid, $importer["uid"], $contact["id"]);

		logger("Profile of contact ".$contact["id"]." stored for user ".$importer["uid"], LOGGER_DEBUG);

		return true;
	}

	/**
	 * @brief Processes incoming friend requests
	 *
	 * @param array $importer Array of the importer user
	 * @param array $contact  The contact that send the request
	 * @return void
	 */
	private static function receiveRequestMakeFriend(array $importer, array $contact)
	{
		$a = get_app();

		if ($contact["rel"] == Contact::SHARING) {
			DBA::update(
				'contact',
				['rel' => Contact::FRIEND, 'writable' => true],
				['id' => $contact["id"], 'uid' => $importer["uid"]]
			);
		}
	}

	/**
	 * @brief Processes incoming sharing notification
	 *
	 * @param array  $importer Array of the importer user
	 * @param object $data     The message object
	 *
	 * @return bool Success
	 */
	private static function receiveContactRequest(array $importer, $data)
	{
		$author = unxmlify($data->author);
		$recipient = unxmlify($data->recipient);

		if (!$author || !$recipient) {
			return false;
		}

		// the current protocol version doesn't know these fields
		// That means that we will assume their existance
		if (isset($data->following)) {
			$following = (unxmlify($data->following) == "true");
		} else {
			$following = true;
		}

		if (isset($data->sharing)) {
			$sharing = (unxmlify($data->sharing) == "true");
		} else {
			$sharing = true;
		}

		$contact = self::contactByHandle($importer["uid"], $author);

		// perhaps we were already sharing with this person. Now they're sharing with us.
		// That makes us friends.
		if ($contact) {
			if ($following) {
				logger("Author ".$author." (Contact ".$contact["id"].") wants to follow us.", LOGGER_DEBUG);
				self::receiveRequestMakeFriend($importer, $contact);

				// refetch the contact array
				$contact = self::contactByHandle($importer["uid"], $author);

				// If we are now friends, we are sending a share message.
				// Normally we needn't to do so, but the first message could have been vanished.
				if (in_array($contact["rel"], [Contact::FRIEND])) {
					$user = DBA::selectFirst('user', [], ['uid' => $importer["uid"]]);
					if (DBA::isResult($user)) {
						logger("Sending share message to author ".$author." - Contact: ".$contact["id"]." - User: ".$importer["uid"], LOGGER_DEBUG);
						$ret = self::sendShare($user, $contact);
					}
				}
				return true;
			} else {
				logger("Author ".$author." doesn't want to follow us anymore.", LOGGER_DEBUG);
				Contact::removeFollower($importer, $contact);
				return true;
			}
		}

		if (!$following && $sharing && in_array($importer["page-flags"], [Contact::PAGE_SOAPBOX, Contact::PAGE_NORMAL])) {
			logger("Author ".$author." wants to share with us - but doesn't want to listen. Request is ignored.", LOGGER_DEBUG);
			return false;
		} elseif (!$following && !$sharing) {
			logger("Author ".$author." doesn't want anything - and we don't know the author. Request is ignored.", LOGGER_DEBUG);
			return false;
		} elseif (!$following && $sharing) {
			logger("Author ".$author." wants to share with us.", LOGGER_DEBUG);
		} elseif ($following && $sharing) {
			logger("Author ".$author." wants to have a bidirectional conection.", LOGGER_DEBUG);
		} elseif ($following && !$sharing) {
			logger("Author ".$author." wants to listen to us.", LOGGER_DEBUG);
		}

		$ret = self::personByHandle($author);

		if (!$ret || ($ret["network"] != Protocol::DIASPORA)) {
			logger("Cannot resolve diaspora handle ".$author." for ".$recipient);
			return false;
		}

		$batch = (($ret["batch"]) ? $ret["batch"] : implode("/", array_slice(explode("/", $ret["url"]), 0, 3))."/receive/public");

		$r = q(
			"INSERT INTO `contact` (`uid`, `network`,`addr`,`created`,`url`,`nurl`,`batch`,`name`,`nick`,`photo`,`pubkey`,`notify`,`poll`,`blocked`,`priority`)
			VALUES (%d, '%s', '%s', '%s', '%s','%s','%s','%s','%s','%s','%s','%s','%s',%d,%d)",
			intval($importer["uid"]),
			DBA::escape($ret["network"]),
			DBA::escape($ret["addr"]),
			DateTimeFormat::utcNow(),
			DBA::escape($ret["url"]),
			DBA::escape(normalise_link($ret["url"])),
			DBA::escape($batch),
			DBA::escape($ret["name"]),
			DBA::escape($ret["nick"]),
			DBA::escape($ret["photo"]),
			DBA::escape($ret["pubkey"]),
			DBA::escape($ret["notify"]),
			DBA::escape($ret["poll"]),
			1,
			2
		);

		// find the contact record we just created

		$contact_record = self::contactByHandle($importer["uid"], $author);

		if (!$contact_record) {
			logger("unable to locate newly created contact record.");
			return;
		}

		logger("Author ".$author." was added as contact number ".$contact_record["id"].".", LOGGER_DEBUG);

		Group::addMember(User::getDefaultGroup($importer['uid'], $ret["network"]), $contact_record['id']);

		Contact::updateAvatar($ret["photo"], $importer['uid'], $contact_record["id"], true);

		if (in_array($importer["page-flags"], [Contact::PAGE_NORMAL, Contact::PAGE_PRVGROUP])) {
			logger("Sending intra message for author ".$author.".", LOGGER_DEBUG);

			$hash = random_string().(string)time();   // Generate a confirm_key

			$ret = q(
				"INSERT INTO `intro` (`uid`, `contact-id`, `blocked`, `knowyou`, `note`, `hash`, `datetime`)
				VALUES (%d, %d, %d, %d, '%s', '%s', '%s')",
				intval($importer["uid"]),
				intval($contact_record["id"]),
				0,
				0,
				DBA::escape(L10n::t("Sharing notification from Diaspora network")),
				DBA::escape($hash),
				DBA::escape(DateTimeFormat::utcNow())
			);
		} else {
			// automatic friend approval

			logger("Does an automatic friend approval for author ".$author.".", LOGGER_DEBUG);

			Contact::updateAvatar($contact_record["photo"], $importer["uid"], $contact_record["id"]);

			// technically they are sharing with us (Contact::SHARING),
			// but if our page-type is PAGE_COMMUNITY or PAGE_SOAPBOX
			// we are going to change the relationship and make them a follower.

			if (($importer["page-flags"] == Contact::PAGE_FREELOVE) && $sharing && $following) {
				$new_relation = Contact::FRIEND;
			} elseif (($importer["page-flags"] == Contact::PAGE_FREELOVE) && $sharing) {
				$new_relation = Contact::SHARING;
			} else {
				$new_relation = Contact::FOLLOWER;
			}

			$r = q(
				"UPDATE `contact` SET `rel` = %d,
				`name-date` = '%s',
				`uri-date` = '%s',
				`blocked` = 0,
				`pending` = 0,
				`writable` = 1
				WHERE `id` = %d
				",
				intval($new_relation),
				DBA::escape(DateTimeFormat::utcNow()),
				DBA::escape(DateTimeFormat::utcNow()),
				intval($contact_record["id"])
			);

			$user = DBA::selectFirst('user', [], ['uid' => $importer["uid"]]);
			if (DBA::isResult($user)) {
				logger("Sending share message (Relation: ".$new_relation.") to author ".$author." - Contact: ".$contact_record["id"]." - User: ".$importer["uid"], LOGGER_DEBUG);
				$ret = self::sendShare($user, $contact_record);

				// Send the profile data, maybe it weren't transmitted before
				self::sendProfile($importer["uid"], [$contact_record]);
			}
		}

		return true;
	}

	/**
	 * @brief Fetches a message with a given guid
	 *
	 * @param string $guid        message guid
	 * @param string $orig_author handle of the original post
	 * @param string $author      handle of the sharer
	 *
	 * @return array The fetched item
	 */
	public static function originalItem($guid, $orig_author)
	{
		if (empty($guid)) {
			logger('Empty guid. Quitting.');
			return false;
		}

		// Do we already have this item?
		$fields = ['body', 'tag', 'app', 'created', 'object-type', 'uri', 'guid',
			'author-name', 'author-link', 'author-avatar'];
		$condition = ['guid' => $guid, 'visible' => true, 'deleted' => false, 'private' => false];
		$item = Item::selectFirst($fields, $condition);

		if (DBA::isResult($item)) {
			logger("reshared message ".$guid." already exists on system.");

			// Maybe it is already a reshared item?
			// Then refetch the content, if it is a reshare from a reshare.
			// If it is a reshared post from another network then reformat to avoid display problems with two share elements
			if (self::isReshare($item["body"], true)) {
				$item = [];
			} elseif (self::isReshare($item["body"], false) || strstr($item["body"], "[share")) {
				$item["body"] = Markdown::toBBCode(BBCode::toMarkdown($item["body"]));

				$item["body"] = self::replacePeopleGuid($item["body"], $item["author-link"]);

				// Add OEmbed and other information to the body
				$item["body"] = add_page_info_to_body($item["body"], false, true);

				return $item;
			} else {
				return $item;
			}
		}

		if (!DBA::isResult($item)) {
			if (empty($orig_author)) {
				logger('Empty author for guid ' . $guid . '. Quitting.');
				return false;
			}

			$server = "https://".substr($orig_author, strpos($orig_author, "@") + 1);
			logger("1st try: reshared message ".$guid." will be fetched via SSL from the server ".$server);
			$stored = self::storeByGuid($guid, $server);

			if (!$stored) {
				$server = "http://".substr($orig_author, strpos($orig_author, "@") + 1);
				logger("2nd try: reshared message ".$guid." will be fetched without SSL from the server ".$server);
				$stored = self::storeByGuid($guid, $server);
			}

			if ($stored) {
				$fields = ['body', 'tag', 'app', 'created', 'object-type', 'uri', 'guid',
					'author-name', 'author-link', 'author-avatar'];
				$condition = ['guid' => $guid, 'visible' => true, 'deleted' => false, 'private' => false];
				$item = Item::selectFirst($fields, $condition);

				if (DBA::isResult($item)) {
					// If it is a reshared post from another network then reformat to avoid display problems with two share elements
					if (self::isReshare($item["body"], false)) {
						$item["body"] = Markdown::toBBCode(BBCode::toMarkdown($item["body"]));
						$item["body"] = self::replacePeopleGuid($item["body"], $item["author-link"]);
					}

					return $item;
				}
			}
		}
		return false;
	}

	/**
	 * @brief Processes a reshare message
	 *
	 * @param array  $importer Array of the importer user
	 * @param object $data     The message object
	 * @param string $xml      The original XML of the message
	 *
	 * @return int the message id
	 */
	private static function receiveReshare(array $importer, $data, $xml)
	{
		$author = notags(unxmlify($data->author));
		$guid = notags(unxmlify($data->guid));
		$created_at = DateTimeFormat::utc(notags(unxmlify($data->created_at)));
		$root_author = notags(unxmlify($data->root_author));
		$root_guid = notags(unxmlify($data->root_guid));
		/// @todo handle unprocessed property "provider_display_name"
		$public = notags(unxmlify($data->public));

		$contact = self::allowedContactByHandle($importer, $author, false);
		if (!$contact) {
			return false;
		}

		$message_id = self::messageExists($importer["uid"], $guid);
		if ($message_id) {
			return true;
		}

		$original_item = self::originalItem($root_guid, $root_author);
		if (!$original_item) {
			return false;
		}

		$orig_url = System::baseUrl()."/display/".$original_item["guid"];

		$datarray = [];

		$datarray["uid"] = $importer["uid"];
		$datarray["contact-id"] = $contact["id"];
		$datarray["network"]  = Protocol::DIASPORA;

		$datarray["author-link"] = $contact["url"];
		$datarray["author-id"] = Contact::getIdForURL($contact["url"], 0);

		$datarray["owner-link"] = $datarray["author-link"];
		$datarray["owner-id"] = $datarray["author-id"];

		$datarray["guid"] = $guid;
		$datarray["uri"] = $datarray["parent-uri"] = self::getUriFromGuid($author, $guid);

		$datarray["verb"] = ACTIVITY_POST;
		$datarray["gravity"] = GRAVITY_PARENT;

		$datarray["protocol"] = Conversation::PARCEL_DIASPORA;
		$datarray["source"] = $xml;

		$prefix = share_header(
			$original_item["author-name"],
			$original_item["author-link"],
			$original_item["author-avatar"],
			$original_item["guid"],
			$original_item["created"],
			$orig_url
		);
		$datarray["body"] = $prefix.$original_item["body"]."[/share]";

		$datarray["tag"] = $original_item["tag"];
		$datarray["app"]  = $original_item["app"];

		$datarray["plink"] = self::plink($author, $guid);
		$datarray["private"] = (($public == "false") ? 1 : 0);
		$datarray["changed"] = $datarray["created"] = $datarray["edited"] = $created_at;

		$datarray["object-type"] = $original_item["object-type"];

		self::fetchGuid($datarray);
		$message_id = Item::insert($datarray);

		self::sendParticipation($contact, $datarray);

		if ($message_id) {
			logger("Stored reshare ".$datarray["guid"]." with message id ".$message_id, LOGGER_DEBUG);
			if ($datarray['uid'] == 0) {
				Item::distribute($message_id);
			}
			return true;
		} else {
			return false;
		}
	}

	/**
	 * @brief Processes retractions
	 *
	 * @param array  $importer Array of the importer user
	 * @param array  $contact  The contact of the item owner
	 * @param object $data     The message object
	 *
	 * @return bool success
	 */
	private static function itemRetraction(array $importer, array $contact, $data)
	{
		$author = notags(unxmlify($data->author));
		$target_guid = notags(unxmlify($data->target_guid));
		$target_type = notags(unxmlify($data->target_type));

		$person = self::personByHandle($author);
		if (!is_array($person)) {
			logger("unable to find author detail for ".$author);
			return false;
		}

		if (empty($contact["url"])) {
			$contact["url"] = $person["url"];
		}

		// Fetch items that are about to be deleted
		$fields = ['uid', 'id', 'parent', 'parent-uri', 'author-link', 'file'];

		// When we receive a public retraction, we delete every item that we find.
		if ($importer['uid'] == 0) {
			$condition = ['guid' => $target_guid, 'deleted' => false];
		} else {
			$condition = ['guid' => $target_guid, 'deleted' => false, 'uid' => $importer['uid']];
		}

		$r = Item::select($fields, $condition);
		if (!DBA::isResult($r)) {
			logger("Target guid ".$target_guid." was not found on this system for user ".$importer['uid'].".");
			return false;
		}

		while ($item = Item::fetch($r)) {
			if (strstr($item['file'], '[')) {
				logger("Target guid " . $target_guid . " for user " . $item['uid'] . " is filed. So it won't be deleted.", LOGGER_DEBUG);
				continue;
			}

			// Fetch the parent item
			$parent = Item::selectFirst(['author-link'], ['id' => $item["parent"]]);

			// Only delete it if the parent author really fits
			if (!link_compare($parent["author-link"], $contact["url"]) && !link_compare($item["author-link"], $contact["url"])) {
				logger("Thread author ".$parent["author-link"]." and item author ".$item["author-link"]." don't fit to expected contact ".$contact["url"], LOGGER_DEBUG);
				continue;
			}

			Item::delete(['id' => $item['id']]);

			logger("Deleted target ".$target_guid." (".$item["id"].") from user ".$item["uid"]." parent: ".$item["parent"], LOGGER_DEBUG);
		}

		return true;
	}

	/**
	 * @brief Receives retraction messages
	 *
	 * @param array  $importer Array of the importer user
	 * @param string $sender   The sender of the message
	 * @param object $data     The message object
	 *
	 * @return bool Success
	 */
	private static function receiveRetraction(array $importer, $sender, $data)
	{
		$target_type = notags(unxmlify($data->target_type));

		$contact = self::contactByHandle($importer["uid"], $sender);
		if (!$contact && (in_array($target_type, ["Contact", "Person"]))) {
			logger("cannot find contact for sender: ".$sender." and user ".$importer["uid"]);
			return false;
		}

		if (!$contact) {
			$contact = [];
		}

		logger("Got retraction for ".$target_type.", sender ".$sender." and user ".$importer["uid"], LOGGER_DEBUG);

		switch ($target_type) {
			case "Comment":
			case "Like":
			case "Post":
			case "Reshare":
			case "StatusMessage":
				return self::itemRetraction($importer, $contact, $data);

			case "PollParticipation":
			case "Photo":
				// Currently unsupported
				break;

			default:
				logger("Unknown target type ".$target_type);
				return false;
		}
		return true;
	}

	/**
	 * @brief Receives status messages
	 *
	 * @param array  $importer Array of the importer user
	 * @param object $data     The message object
	 * @param string $xml      The original XML of the message
	 *
	 * @return int The message id of the newly created item
	 */
	private static function receiveStatusMessage(array $importer, $data, $xml)
	{
		$author = notags(unxmlify($data->author));
		$guid = notags(unxmlify($data->guid));
		$created_at = DateTimeFormat::utc(notags(unxmlify($data->created_at)));
		$public = notags(unxmlify($data->public));
		$text = unxmlify($data->text);
		$provider_display_name = notags(unxmlify($data->provider_display_name));

		$contact = self::allowedContactByHandle($importer, $author, false);
		if (!$contact) {
			return false;
		}

		$message_id = self::messageExists($importer["uid"], $guid);
		if ($message_id) {
			return true;
		}

		$address = [];
		if ($data->location) {
			foreach ($data->location->children() as $fieldname => $data) {
				$address[$fieldname] = notags(unxmlify($data));
			}
		}

		$body = Markdown::toBBCode($text);

		$datarray = [];

		// Attach embedded pictures to the body
		if ($data->photo) {
			foreach ($data->photo as $photo) {
				$body = "[img]".unxmlify($photo->remote_photo_path).
					unxmlify($photo->remote_photo_name)."[/img]\n".$body;
			}

			$datarray["object-type"] = ACTIVITY_OBJ_IMAGE;
		} else {
			$datarray["object-type"] = ACTIVITY_OBJ_NOTE;

			// Add OEmbed and other information to the body
			if (!self::isRedmatrix($contact["url"])) {
				$body = add_page_info_to_body($body, false, true);
			}
		}

		/// @todo enable support for polls
		//if ($data->poll) {
		//	foreach ($data->poll AS $poll)
		//		print_r($poll);
		//	die("poll!\n");
		//}

		/// @todo enable support for events

		$datarray["uid"] = $importer["uid"];
		$datarray["contact-id"] = $contact["id"];
		$datarray["network"] = Protocol::DIASPORA;

		$datarray["author-link"] = $contact["url"];
		$datarray["author-id"] = Contact::getIdForURL($contact["url"], 0);

		$datarray["owner-link"] = $datarray["author-link"];
		$datarray["owner-id"] = $datarray["author-id"];

		$datarray["guid"] = $guid;
		$datarray["uri"] = $datarray["parent-uri"] = self::getUriFromGuid($author, $guid);

		$datarray["verb"] = ACTIVITY_POST;
		$datarray["gravity"] = GRAVITY_PARENT;

		$datarray["protocol"] = Conversation::PARCEL_DIASPORA;
		$datarray["source"] = $xml;

		$datarray["body"] = self::replacePeopleGuid($body, $contact["url"]);

		if ($provider_display_name != "") {
			$datarray["app"] = $provider_display_name;
		}

		$datarray["plink"] = self::plink($author, $guid);
		$datarray["private"] = (($public == "false") ? 1 : 0);
		$datarray["changed"] = $datarray["created"] = $datarray["edited"] = $created_at;

		if (isset($address["address"])) {
			$datarray["location"] = $address["address"];
		}

		if (isset($address["lat"]) && isset($address["lng"])) {
			$datarray["coord"] = $address["lat"]." ".$address["lng"];
		}

		self::fetchGuid($datarray);
		$message_id = Item::insert($datarray);

		self::sendParticipation($contact, $datarray);

		if ($message_id) {
			logger("Stored item ".$datarray["guid"]." with message id ".$message_id, LOGGER_DEBUG);
			if ($datarray['uid'] == 0) {
				Item::distribute($message_id);
			}
			return true;
		} else {
			return false;
		}
	}

	/* ************************************************************************************** *
	 * Here are all the functions that are needed to transmit data with the Diaspora protocol *
	 * ************************************************************************************** */

	/**
	 * @brief returnes the handle of a contact
	 *
	 * @param array $contact contact array
	 *
	 * @return string the handle in the format user@domain.tld
	 */
	private static function myHandle(array $contact)
	{
		if (!empty($contact["addr"])) {
			return $contact["addr"];
		}

		// Normally we should have a filled "addr" field - but in the past this wasn't the case
		// So - just in case - we build the the address here.
		if ($contact["nickname"] != "") {
			$nick = $contact["nickname"];
		} else {
			$nick = $contact["nick"];
		}

		return $nick . "@" . substr(System::baseUrl(), strpos(System::baseUrl(), "://") + 3);
	}


	/**
	 * @brief Creates the data for a private message in the new format
	 *
	 * @param string $msg     The message that is to be transmitted
	 * @param array  $user    The record of the sender
	 * @param array  $contact Target of the communication
	 * @param string $prvkey  The private key of the sender
	 * @param string $pubkey  The public key of the receiver
	 *
	 * @return string The encrypted data
	 */
	public static function encodePrivateData($msg, array $user, array $contact, $prvkey, $pubkey)
	{
		logger("Message: ".$msg, LOGGER_DATA);

		// without a public key nothing will work
		if (!$pubkey) {
			logger("pubkey missing: contact id: ".$contact["id"]);
			return false;
		}

		$aes_key = openssl_random_pseudo_bytes(32);
		$b_aes_key = base64_encode($aes_key);
		$iv = openssl_random_pseudo_bytes(16);
		$b_iv = base64_encode($iv);

		$ciphertext = self::aesEncrypt($aes_key, $iv, $msg);

		$json = json_encode(["iv" => $b_iv, "key" => $b_aes_key]);

		$encrypted_key_bundle = "";
		openssl_public_encrypt($json, $encrypted_key_bundle, $pubkey);

		$json_object = json_encode(
			["aes_key" => base64_encode($encrypted_key_bundle),
					"encrypted_magic_envelope" => base64_encode($ciphertext)]
		);

		return $json_object;
	}

	/**
	 * @brief Creates the envelope for the "fetch" endpoint and for the new format
	 *
	 * @param string $msg  The message that is to be transmitted
	 * @param array  $user The record of the sender
	 *
	 * @return string The envelope
	 */
	public static function buildMagicEnvelope($msg, array $user)
	{
		$b64url_data = base64url_encode($msg);
		$data = str_replace(["\n", "\r", " ", "\t"], ["", "", "", ""], $b64url_data);

		$key_id = base64url_encode(self::myHandle($user));
		$type = "application/xml";
		$encoding = "base64url";
		$alg = "RSA-SHA256";
		$signable_data = $data.".".base64url_encode($type).".".base64url_encode($encoding).".".base64url_encode($alg);

		// Fallback if the private key wasn't transmitted in the expected field
		if ($user['uprvkey'] == "") {
			$user['uprvkey'] = $user['prvkey'];
		}

		$signature = Crypto::rsaSign($signable_data, $user["uprvkey"]);
		$sig = base64url_encode($signature);

		$xmldata = ["me:env" => ["me:data" => $data,
							"@attributes" => ["type" => $type],
							"me:encoding" => $encoding,
							"me:alg" => $alg,
							"me:sig" => $sig,
							"@attributes2" => ["key_id" => $key_id]]];

		$namespaces = ["me" => "http://salmon-protocol.org/ns/magic-env"];

		return XML::fromArray($xmldata, $xml, false, $namespaces);
	}

	/**
	 * @brief Create the envelope for a message
	 *
	 * @param string $msg     The message that is to be transmitted
	 * @param array  $user    The record of the sender
	 * @param array  $contact Target of the communication
	 * @param string $prvkey  The private key of the sender
	 * @param string $pubkey  The public key of the receiver
	 * @param bool   $public  Is the message public?
	 *
	 * @return string The message that will be transmitted to other servers
	 */
	public static function buildMessage($msg, array $user, array $contact, $prvkey, $pubkey, $public = false)
	{
		// The message is put into an envelope with the sender's signature
		$envelope = self::buildMagicEnvelope($msg, $user);

		// Private messages are put into a second envelope, encrypted with the receivers public key
		if (!$public) {
			$envelope = self::encodePrivateData($envelope, $user, $contact, $prvkey, $pubkey);
		}

		return $envelope;
	}

	/**
	 * @brief Creates a signature for a message
	 *
	 * @param array $owner   the array of the owner of the message
	 * @param array $message The message that is to be signed
	 *
	 * @return string The signature
	 */
	private static function signature($owner, $message)
	{
		$sigmsg = $message;
		unset($sigmsg["author_signature"]);
		unset($sigmsg["parent_author_signature"]);

		$signed_text = implode(";", $sigmsg);

		return base64_encode(Crypto::rsaSign($signed_text, $owner["uprvkey"], "sha256"));
	}

	/**
	 * @brief Transmit a message to a target server
	 *
	 * @param array  $owner        the array of the item owner
	 * @param array  $contact      Target of the communication
	 * @param string $envelope     The message that is to be transmitted
	 * @param bool   $public_batch Is it a public post?
	 * @param bool   $queue_run    Is the transmission called from the queue?
	 * @param string $guid         message guid
	 *
	 * @return int Result of the transmission
	 */
	public static function transmit(array $owner, array $contact, $envelope, $public_batch, $queue_run = false, $guid = "", $no_queue = false)
	{
		$a = get_app();

		$enabled = intval(Config::get("system", "diaspora_enabled"));
		if (!$enabled) {
			return 200;
		}

		$logid = random_string(4);

		$dest_url = ($public_batch ? $contact["batch"] : $contact["notify"]);

		// We always try to use the data from the fcontact table.
		// This is important for transmitting data to Friendica servers.
		if (!empty($contact['addr'])) {
			$fcontact = self::personByHandle($contact['addr']);
			if (!empty($fcontact)) {
				$dest_url = ($public_batch ? $fcontact["batch"] : $fcontact["notify"]);
			}
		}

		if (!$dest_url) {
			logger("no url for contact: ".$contact["id"]." batch mode =".$public_batch);
			return 0;
		}

		logger("transmit: ".$logid."-".$guid." ".$dest_url);

		if (!$queue_run && Queue::wasDelayed($contact["id"])) {
			$return_code = 0;
		} else {
			if (!intval(Config::get("system", "diaspora_test"))) {
				$content_type = (($public_batch) ? "application/magic-envelope+xml" : "application/json");

				Network::post($dest_url."/", $envelope, ["Content-Type: ".$content_type]);
				$return_code = $a->get_curl_code();
			} else {
				logger("test_mode");
				return 200;
			}
		}

		logger("transmit: ".$logid."-".$guid." to ".$dest_url." returns: ".$return_code);

		if (!$return_code || (($return_code == 503) && (stristr($a->get_curl_headers(), "retry-after")))) {
			if (!$no_queue && !empty($contact['contact-type']) && ($contact['contact-type'] != Contact::ACCOUNT_TYPE_RELAY)) {
				logger("queue message");
				// queue message for redelivery
				Queue::add($contact["id"], Protocol::DIASPORA, $envelope, $public_batch, $guid);
			}

			// The message could not be delivered. We mark the contact as "dead"
			Contact::markForArchival($contact);
		} elseif (($return_code >= 200) && ($return_code <= 299)) {
			// We successfully delivered a message, the contact is alive
			Contact::unmarkForArchival($contact);
		}

		return $return_code ? $return_code : -1;
	}


	/**
	 * @brief Build the post xml
	 *
	 * @param string $type    The message type
	 * @param array  $message The message data
	 *
	 * @return string The post XML
	 */
	public static function buildPostXml($type, $message)
	{
		$data = [$type => $message];

		return XML::fromArray($data, $xml);
	}

	/**
	 * @brief Builds and transmit messages
	 *
	 * @param array  $owner        the array of the item owner
	 * @param array  $contact      Target of the communication
	 * @param string $type         The message type
	 * @param array  $message      The message data
	 * @param bool   $public_batch Is it a public post?
	 * @param string $guid         message guid
	 * @param bool   $spool        Should the transmission be spooled or transmitted?
	 *
	 * @return int Result of the transmission
	 */
	private static function buildAndTransmit(array $owner, array $contact, $type, $message, $public_batch = false, $guid = "", $spool = false)
	{
		$msg = self::buildPostXml($type, $message);

		logger('message: '.$msg, LOGGER_DATA);
		logger('send guid '.$guid, LOGGER_DEBUG);

		// Fallback if the private key wasn't transmitted in the expected field
		if (empty($owner['uprvkey'])) {
			$owner['uprvkey'] = $owner['prvkey'];
		}

		$envelope = self::buildMessage($msg, $owner, $contact, $owner['uprvkey'], $contact['pubkey'], $public_batch);

		if ($spool) {
			Queue::add($contact['id'], Protocol::DIASPORA, $envelope, $public_batch, $guid);
			return true;
		} else {
			$return_code = self::transmit($owner, $contact, $envelope, $public_batch, false, $guid);
		}

		logger("guid: ".$guid." result ".$return_code, LOGGER_DEBUG);

		return $return_code;
	}

	/**
	 * @brief sends a participation (Used to get all further updates)
	 *
	 * @param array $contact Target of the communication
	 * @param array $item	 Item array
	 *
	 * @return int The result of the transmission
	 */
	private static function sendParticipation(array $contact, array $item)
	{
		// Don't send notifications for private postings
		if ($item['private']) {
			return;
		}

		$cachekey = "diaspora:sendParticipation:".$item['guid'];

		$result = Cache::get($cachekey);
		if (!is_null($result)) {
			return;
		}

		// Fetch some user id to have a valid handle to transmit the participation.
		// In fact it doesn't matter which user sends this - but it is needed by the protocol.
		// If the item belongs to a user, we take this user id.
		if ($item['uid'] == 0) {
			$condition = ['verified' => true, 'blocked' => false, 'account_removed' => false, 'account_expired' => false];
			$first_user = DBA::selectFirst('user', ['uid'], $condition);
			$owner = User::getOwnerDataById($first_user['uid']);
		} else {
			$owner = User::getOwnerDataById($item['uid']);
		}

		$author = self::myHandle($owner);

		$message = ["author" => $author,
				"guid" => System::createGUID(32),
				"parent_type" => "Post",
				"parent_guid" => $item["guid"]];

		logger("Send participation for ".$item["guid"]." by ".$author, LOGGER_DEBUG);

		// It doesn't matter what we store, we only want to avoid sending repeated notifications for the same item
		Cache::set($cachekey, $item["guid"], CACHE_QUARTER_HOUR);

		return self::buildAndTransmit($owner, $contact, "participation", $message);
	}

	/**
	 * @brief sends an account migration
	 *
	 * @param array $owner   the array of the item owner
	 * @param array $contact Target of the communication
	 * @param int 	$uid     User ID
	 *
	 * @return int The result of the transmission
	 */
	public static function sendAccountMigration(array $owner, array $contact, $uid)
	{
		$old_handle = PConfig::get($uid, 'system', 'previous_addr');
		$profile = self::createProfileData($uid);

		$signed_text = 'AccountMigration:'.$old_handle.':'.$profile['author'];
		$signature = base64_encode(Crypto::rsaSign($signed_text, $owner["uprvkey"], "sha256"));

		$message = ["author" => $old_handle,
				"profile" => $profile,
				"signature" => $signature];

		logger("Send account migration ".print_r($message, true), LOGGER_DEBUG);

		return self::buildAndTransmit($owner, $contact, "account_migration", $message);
	}

	/**
	 * @brief Sends a "share" message
	 *
	 * @param array $owner   the array of the item owner
	 * @param array $contact Target of the communication
	 *
	 * @return int The result of the transmission
	 */
	public static function sendShare(array $owner, array $contact)
	{
		/**
		 * @todo support the different possible combinations of "following" and "sharing"
		 * Currently, Diaspora only interprets the "sharing" field
		 *
		 * Before switching this code productive, we have to check all "sendShare" calls if "rel" is set correctly
		 */

		/*
		switch ($contact["rel"]) {
			case Contact::FRIEND:
				$following = true;
				$sharing = true;

			case Contact::SHARING:
				$following = false;
				$sharing = true;

			case Contact::FOLLOWER:
				$following = true;
				$sharing = false;
		}
		*/

		$message = ["author" => self::myHandle($owner),
				"recipient" => $contact["addr"],
				"following" => "true",
				"sharing" => "true"];

		logger("Send share ".print_r($message, true), LOGGER_DEBUG);

		return self::buildAndTransmit($owner, $contact, "contact", $message);
	}

	/**
	 * @brief sends an "unshare"
	 *
	 * @param array $owner   the array of the item owner
	 * @param array $contact Target of the communication
	 *
	 * @return int The result of the transmission
	 */
	public static function sendUnshare(array $owner, array $contact)
	{
		$message = ["author" => self::myHandle($owner),
				"recipient" => $contact["addr"],
				"following" => "false",
				"sharing" => "false"];

		logger("Send unshare ".print_r($message, true), LOGGER_DEBUG);

		return self::buildAndTransmit($owner, $contact, "contact", $message);
	}

	/**
	 * @brief Checks a message body if it is a reshare
	 *
	 * @param string $body     The message body that is to be check
	 * @param bool   $complete Should it be a complete check or a simple check?
	 *
	 * @return array|bool Reshare details or "false" if no reshare
	 */
	public static function isReshare($body, $complete = true)
	{
		$body = trim($body);

		// Skip if it isn't a pure repeated messages
		// Does it start with a share?
		if ((strpos($body, "[share") > 0) && $complete) {
			return false;
		}

		// Does it end with a share?
		if (strlen($body) > (strrpos($body, "[/share]") + 8)) {
			return false;
		}

		$attributes = preg_replace("/\[share(.*?)\]\s?(.*?)\s?\[\/share\]\s?/ism", "$1", $body);
		// Skip if there is no shared message in there
		if ($body == $attributes) {
			return false;
		}

		// If we don't do the complete check we quit here

		$guid = "";
		preg_match("/guid='(.*?)'/ism", $attributes, $matches);
		if (!empty($matches[1])) {
			$guid = $matches[1];
		}

		preg_match('/guid="(.*?)"/ism', $attributes, $matches);
		if (!empty($matches[1])) {
			$guid = $matches[1];
		}

		if (($guid != "") && $complete) {
			$condition = ['guid' => $guid, 'network' => [Protocol::DFRN, Protocol::DIASPORA]];
			$item = Item::selectFirst(['contact-id'], $condition);
			if (DBA::isResult($item)) {
				$ret= [];
				$ret["root_handle"] = self::handleFromContact($item["contact-id"]);
				$ret["root_guid"] = $guid;
				return $ret;
			} elseif ($complete) {
				// We are resharing something that isn't a DFRN or Diaspora post.
				// So we have to return "false" on "$complete" to not trigger a reshare.
				return false;
			}
		} elseif (($guid == "") && $complete) {
			return false;
		}

		$ret["root_guid"] = $guid;

		$profile = "";
		preg_match("/profile='(.*?)'/ism", $attributes, $matches);
		if (!empty($matches[1])) {
			$profile = $matches[1];
		}

		preg_match('/profile="(.*?)"/ism', $attributes, $matches);
		if (!empty($matches[1])) {
			$profile = $matches[1];
		}

		$ret= [];

		if ($profile != "") {
			if (Contact::getIdForURL($profile)) {
				$author = Contact::getDetailsByURL($profile);
				$ret["root_handle"] = $author['addr'];
			}
		}

		if (empty($ret) && !$complete) {
			return true;
		}

		return $ret;
	}

	/**
	 * @brief Create an event array
	 *
	 * @param integer $event_id The id of the event
	 *
	 * @return array with event data
	 */
	private static function buildEvent($event_id)
	{
		$r = q("SELECT `guid`, `uid`, `start`, `finish`, `nofinish`, `summary`, `desc`, `location`, `adjust` FROM `event` WHERE `id` = %d", intval($event_id));
		if (!DBA::isResult($r)) {
			return [];
		}

		$event = $r[0];

		$eventdata = [];

		$r = q("SELECT `timezone` FROM `user` WHERE `uid` = %d", intval($event['uid']));
		if (!DBA::isResult($r)) {
			return [];
		}

		$user = $r[0];

		$r = q("SELECT `addr`, `nick` FROM `contact` WHERE `uid` = %d AND `self`", intval($event['uid']));
		if (!DBA::isResult($r)) {
			return [];
		}

		$owner = $r[0];

		$eventdata['author'] = self::myHandle($owner);

		if ($event['guid']) {
			$eventdata['guid'] = $event['guid'];
		}

		$mask = DateTimeFormat::ATOM;

		/// @todo - establish "all day" events in Friendica
		$eventdata["all_day"] = "false";

		if (!$event['adjust']) {
			$eventdata['timezone'] = $user['timezone'];

			if ($eventdata['timezone'] == "") {
				$eventdata['timezone'] = 'UTC';
			}
		}

		if ($event['start']) {
			$eventdata['start'] = DateTimeFormat::convert($event['start'], "UTC", $eventdata['timezone'], $mask);
		}
		if ($event['finish'] && !$event['nofinish']) {
			$eventdata['end'] = DateTimeFormat::convert($event['finish'], "UTC", $eventdata['timezone'], $mask);
		}
		if ($event['summary']) {
			$eventdata['summary'] = html_entity_decode(BBCode::toMarkdown($event['summary']));
		}
		if ($event['desc']) {
			$eventdata['description'] = html_entity_decode(BBCode::toMarkdown($event['desc']));
		}
		if ($event['location']) {
			$event['location'] = preg_replace("/\[map\](.*?)\[\/map\]/ism", '$1', $event['location']);
			$coord = Map::getCoordinates($event['location']);

			$location = [];
			$location["address"] = html_entity_decode(BBCode::toMarkdown($event['location']));
			if (!empty($coord['lat']) && !empty($coord['lon'])) {
				$location["lat"] = $coord['lat'];
				$location["lng"] = $coord['lon'];
			} else {
				$location["lat"] = 0;
				$location["lng"] = 0;
			}
			$eventdata['location'] = $location;
		}

		return $eventdata;
	}

	/**
	 * @brief Create a post (status message or reshare)
	 *
	 * @param array $item  The item that will be exported
	 * @param array $owner the array of the item owner
	 *
	 * @return array
	 * 'type' -> Message type ("status_message" or "reshare")
	 * 'message' -> Array of XML elements of the status
	 */
	public static function buildStatus(array $item, array $owner)
	{
		$cachekey = "diaspora:buildStatus:".$item['guid'];

		$result = Cache::get($cachekey);
		if (!is_null($result)) {
			return $result;
		}

		$myaddr = self::myHandle($owner);

		$public = (($item["private"]) ? "false" : "true");

		$created = DateTimeFormat::utc($item["created"], DateTimeFormat::ATOM);

		// Detect a share element and do a reshare
		if (!$item['private'] && ($ret = self::isReshare($item["body"]))) {
			$message = ["author" => $myaddr,
					"guid" => $item["guid"],
					"created_at" => $created,
					"root_author" => $ret["root_handle"],
					"root_guid" => $ret["root_guid"],
					"provider_display_name" => $item["app"],
					"public" => $public];

			$type = "reshare";
		} else {
			$title = $item["title"];
			$body = $item["body"];

			if ($item['author-link'] != $item['owner-link']) {
				require_once 'mod/share.php';
				$body = share_header($item['author-name'], $item['author-link'], $item['author-avatar'],
					"", $item['created'], $item['plink']) . $body . '[/share]';
			}

			// convert to markdown
			$body = html_entity_decode(BBCode::toMarkdown($body));

			// Adding the title
			if (strlen($title)) {
				$body = "## ".html_entity_decode($title)."\n\n".$body;
			}

			if ($item["attach"]) {
				$cnt = preg_match_all('/href=\"(.*?)\"(.*?)title=\"(.*?)\"/ism', $item["attach"], $matches, PREG_SET_ORDER);
				if ($cnt) {
					$body .= "\n".L10n::t("Attachments:")."\n";
					foreach ($matches as $mtch) {
						$body .= "[".$mtch[3]."](".$mtch[1].")\n";
					}
				}
			}

			$location = [];

			if ($item["location"] != "")
				$location["address"] = $item["location"];

			if ($item["coord"] != "") {
				$coord = explode(" ", $item["coord"]);
				$location["lat"] = $coord[0];
				$location["lng"] = $coord[1];
			}

			$message = ["author" => $myaddr,
					"guid" => $item["guid"],
					"created_at" => $created,
					"public" => $public,
					"text" => $body,
					"provider_display_name" => $item["app"],
					"location" => $location];

			// Diaspora rejects messages when they contain a location without "lat" or "lng"
			if (!isset($location["lat"]) || !isset($location["lng"])) {
				unset($message["location"]);
			}

			if ($item['event-id'] > 0) {
				$event = self::buildEvent($item['event-id']);
				if (count($event)) {
					$message['event'] = $event;

					if (!empty($event['location']['address']) &&
						!empty($event['location']['lat']) &&
						!empty($event['location']['lng'])) {
						$message['location'] = $event['location'];
					}

					/// @todo Once Diaspora supports it, we will remove the body and the location hack above
					// $message['text'] = '';
				}
			}

			$type = "status_message";
		}

		$msg = ["type" => $type, "message" => $message];

		Cache::set($cachekey, $msg, CACHE_QUARTER_HOUR);

		return $msg;
	}

	/**
	 * @brief Sends a post
	 *
	 * @param array $item         The item that will be exported
	 * @param array $owner        the array of the item owner
	 * @param array $contact      Target of the communication
	 * @param bool  $public_batch Is it a public post?
	 *
	 * @return int The result of the transmission
	 */
	public static function sendStatus(array $item, array $owner, array $contact, $public_batch = false)
	{
		$status = self::buildStatus($item, $owner);

		return self::buildAndTransmit($owner, $contact, $status["type"], $status["message"], $public_batch, $item["guid"]);
	}

	/**
	 * @brief Creates a "like" object
	 *
	 * @param array $item  The item that will be exported
	 * @param array $owner the array of the item owner
	 *
	 * @return array The data for a "like"
	 */
	private static function constructLike(array $item, array $owner)
	{
		$parent = Item::selectFirst(['guid', 'uri', 'parent-uri'], ['uri' => $item["thr-parent"]]);
		if (!DBA::isResult($parent)) {
			return false;
		}

		$target_type = ($parent["uri"] === $parent["parent-uri"] ? "Post" : "Comment");
		$positive = null;
		if ($item['verb'] === ACTIVITY_LIKE) {
			$positive = "true";
		} elseif ($item['verb'] === ACTIVITY_DISLIKE) {
			$positive = "false";
		}

		return(["author" => self::myHandle($owner),
				"guid" => $item["guid"],
				"parent_guid" => $parent["guid"],
				"parent_type" => $target_type,
				"positive" => $positive,
				"author_signature" => ""]);
	}

	/**
	 * @brief Creates an "EventParticipation" object
	 *
	 * @param array $item  The item that will be exported
	 * @param array $owner the array of the item owner
	 *
	 * @return array The data for an "EventParticipation"
	 */
	private static function constructAttend(array $item, array $owner)
	{
		$parent = Item::selectFirst(['guid', 'uri', 'parent-uri'], ['uri' => $item["thr-parent"]]);
		if (!DBA::isResult($parent)) {
			return false;
		}

		switch ($item['verb']) {
			case ACTIVITY_ATTEND:
				$attend_answer = 'accepted';
				break;
			case ACTIVITY_ATTENDNO:
				$attend_answer = 'declined';
				break;
			case ACTIVITY_ATTENDMAYBE:
				$attend_answer = 'tentative';
				break;
			default:
				logger('Unknown verb '.$item['verb'].' in item '.$item['guid']);
				return false;
		}

		return(["author" => self::myHandle($owner),
				"guid" => $item["guid"],
				"parent_guid" => $parent["guid"],
				"status" => $attend_answer,
				"author_signature" => ""]);
	}

	/**
	 * @brief Creates the object for a comment
	 *
	 * @param array $item  The item that will be exported
	 * @param array $owner the array of the item owner
	 *
	 * @return array The data for a comment
	 */
	private static function constructComment(array $item, array $owner)
	{
		$cachekey = "diaspora:constructComment:".$item['guid'];

		$result = Cache::get($cachekey);
		if (!is_null($result)) {
			return $result;
		}

		$parent = Item::selectFirst(['guid'], ['id' => $item["parent"], 'parent' => $item["parent"]]);
		if (!DBA::isResult($parent)) {
			return false;
		}

		$text = html_entity_decode(BBCode::toMarkdown($item["body"]));
		$created = DateTimeFormat::utc($item["created"], DateTimeFormat::ATOM);

		$comment = ["author" => self::myHandle($owner),
				"guid" => $item["guid"],
				"created_at" => $created,
				"parent_guid" => $parent["guid"],
				"text" => $text,
				"author_signature" => ""];

		// Send the thread parent guid only if it is a threaded comment
		if ($item['thr-parent'] != $item['parent-uri']) {
			$comment['thread_parent_guid'] = self::getGuidFromUri($item['thr-parent'], $item['uid']);
		}

		Cache::set($cachekey, $comment, CACHE_QUARTER_HOUR);

		return($comment);
	}

	/**
	 * @brief Send a like or a comment
	 *
	 * @param array $item         The item that will be exported
	 * @param array $owner        the array of the item owner
	 * @param array $contact      Target of the communication
	 * @param bool  $public_batch Is it a public post?
	 *
	 * @return int The result of the transmission
	 */
	public static function sendFollowup(array $item, array $owner, array $contact, $public_batch = false)
	{
		if (in_array($item['verb'], [ACTIVITY_ATTEND, ACTIVITY_ATTENDNO, ACTIVITY_ATTENDMAYBE])) {
			$message = self::constructAttend($item, $owner);
			$type = "event_participation";
		} elseif (in_array($item["verb"], [ACTIVITY_LIKE, ACTIVITY_DISLIKE])) {
			$message = self::constructLike($item, $owner);
			$type = "like";
		} else {
			$message = self::constructComment($item, $owner);
			$type = "comment";
		}

		if (!$message) {
			return false;
		}

		$message["author_signature"] = self::signature($owner, $message);

		return self::buildAndTransmit($owner, $contact, $type, $message, $public_batch, $item["guid"]);
	}

	/**
	 * @brief Creates a message from a signature record entry
	 *
	 * @param array $item      The item that will be exported
	 * @param array $signature The entry of the "sign" record
	 *
	 * @return string The message
	 */
	private static function messageFromSignature(array $item, array $signature)
	{
		// Split the signed text
		$signed_parts = explode(";", $signature['signed_text']);

		if ($item["deleted"]) {
			$message = ["author" => $signature['signer'],
					"target_guid" => $signed_parts[0],
					"target_type" => $signed_parts[1]];
		} elseif (in_array($item["verb"], [ACTIVITY_LIKE, ACTIVITY_DISLIKE])) {
			$message = ["author" => $signed_parts[4],
					"guid" => $signed_parts[1],
					"parent_guid" => $signed_parts[3],
					"parent_type" => $signed_parts[2],
					"positive" => $signed_parts[0],
					"author_signature" => $signature['signature'],
					"parent_author_signature" => ""];
		} else {
			// Remove the comment guid
			$guid = array_shift($signed_parts);

			// Remove the parent guid
			$parent_guid = array_shift($signed_parts);

			// Remove the handle
			$handle = array_pop($signed_parts);

			// Glue the parts together
			$text = implode(";", $signed_parts);

			$message = ["author" => $handle,
					"guid" => $guid,
					"parent_guid" => $parent_guid,
					"text" => implode(";", $signed_parts),
					"author_signature" => $signature['signature'],
					"parent_author_signature" => ""];
		}
		return $message;
	}

	/**
	 * @brief Relays messages (like, comment, retraction) to other servers if we are the thread owner
	 *
	 * @param array $item         The item that will be exported
	 * @param array $owner        the array of the item owner
	 * @param array $contact      Target of the communication
	 * @param bool  $public_batch Is it a public post?
	 *
	 * @return int The result of the transmission
	 */
	public static function sendRelay(array $item, array $owner, array $contact, $public_batch = false)
	{
		if ($item["deleted"]) {
			return self::sendRetraction($item, $owner, $contact, $public_batch, true);
		} elseif (in_array($item["verb"], [ACTIVITY_LIKE, ACTIVITY_DISLIKE])) {
			$type = "like";
		} else {
			$type = "comment";
		}

		logger("Got relayable data ".$type." for item ".$item["guid"]." (".$item["id"].")", LOGGER_DEBUG);

		// fetch the original signature
		$fields = ['signed_text', 'signature', 'signer'];
		$signature = DBA::selectFirst('sign', $fields, ['iid' => $item["id"]]);
		if (!DBA::isResult($signature)) {
			logger("Couldn't fetch signatur for item ".$item["guid"]." (".$item["id"].")", LOGGER_DEBUG);
			return false;
		}

		// Old way - is used by the internal Friendica functions
		/// @todo Change all signatur storing functions to the new format
		if ($signature['signed_text'] && $signature['signature'] && $signature['signer']) {
			$message = self::messageFromSignature($item, $signature);
		} else {// New way
			$msg = json_decode($signature['signed_text'], true);

			$message = [];
			if (is_array($msg)) {
				foreach ($msg as $field => $data) {
					if (!$item["deleted"]) {
						if ($field == "diaspora_handle") {
							$field = "author";
						}
						if ($field == "target_type") {
							$field = "parent_type";
						}
					}

					$message[$field] = $data;
				}
			} else {
				logger("Signature text for item ".$item["guid"]." (".$item["id"].") couldn't be extracted: ".$signature['signed_text'], LOGGER_DEBUG);
			}
		}

		$message["parent_author_signature"] = self::signature($owner, $message);

		logger("Relayed data ".print_r($message, true), LOGGER_DEBUG);

		return self::buildAndTransmit($owner, $contact, $type, $message, $public_batch, $item["guid"]);
	}

	/**
	 * @brief Sends a retraction (deletion) of a message, like or comment
	 *
	 * @param array $item         The item that will be exported
	 * @param array $owner        the array of the item owner
	 * @param array $contact      Target of the communication
	 * @param bool  $public_batch Is it a public post?
	 * @param bool  $relay        Is the retraction transmitted from a relay?
	 *
	 * @return int The result of the transmission
	 */
	public static function sendRetraction(array $item, array $owner, array $contact, $public_batch = false, $relay = false)
	{
		$itemaddr = self::handleFromContact($item["contact-id"], $item["author-id"]);

		$msg_type = "retraction";

		if ($item['id'] == $item['parent']) {
			$target_type = "Post";
		} elseif (in_array($item["verb"], [ACTIVITY_LIKE, ACTIVITY_DISLIKE])) {
			$target_type = "Like";
		} else {
			$target_type = "Comment";
		}

		$message = ["author" => $itemaddr,
				"target_guid" => $item['guid'],
				"target_type" => $target_type];

		logger("Got message ".print_r($message, true), LOGGER_DEBUG);

		return self::buildAndTransmit($owner, $contact, $msg_type, $message, $public_batch, $item["guid"]);
	}

	/**
	 * @brief Sends a mail
	 *
	 * @param array $item    The item that will be exported
	 * @param array $owner   The owner
	 * @param array $contact Target of the communication
	 *
	 * @return int The result of the transmission
	 */
	public static function sendMail(array $item, array $owner, array $contact)
	{
		$myaddr = self::myHandle($owner);

		$cnv = DBA::selectFirst('conv', [], ['id' => $item["convid"], 'uid' => $item["uid"]]);
		if (!DBA::isResult($cnv)) {
			logger("conversation not found.");
			return;
		}

		$conv = [
			"author" => $cnv["creator"],
			"guid" => $cnv["guid"],
			"subject" => $cnv["subject"],
			"created_at" => DateTimeFormat::utc($cnv['created'], DateTimeFormat::ATOM),
			"participants" => $cnv["recips"]
		];

		$body = BBCode::toMarkdown($item["body"]);
		$created = DateTimeFormat::utc($item["created"], DateTimeFormat::ATOM);

		$msg = [
			"author" => $myaddr,
			"guid" => $item["guid"],
			"conversation_guid" => $cnv["guid"],
			"text" => $body,
			"created_at" => $created,
		];

		if ($item["reply"]) {
			$message = $msg;
			$type = "message";
		} else {
			$message = [
					"author" => $cnv["creator"],
					"guid" => $cnv["guid"],
					"subject" => $cnv["subject"],
					"created_at" => DateTimeFormat::utc($cnv['created'], DateTimeFormat::ATOM),
					"participants" => $cnv["recips"],
					"message" => $msg];

			$type = "conversation";
		}

		return self::buildAndTransmit($owner, $contact, $type, $message, false, $item["guid"]);
	}

	/**
	 * @brief Split a name into first name and last name
	 *
	 * @param string $name The name
	 *
	 * @return array The array with "first" and "last"
	 */
	public static function splitName($name) {
		$name = trim($name);

		// Is the name longer than 64 characters? Then cut the rest of it.
		if (strlen($name) > 64) {
			if ((strpos($name, ' ') <= 64) && (strpos($name, ' ') !== false)) {
				$name = trim(substr($name, 0, strrpos(substr($name, 0, 65), ' ')));
			} else {
				$name = substr($name, 0, 64);
			}
		}

		// Take the first word as first name
		$first = ((strpos($name, ' ') ? trim(substr($name, 0, strpos($name, ' '))) : $name));
		$last = (($first === $name) ? '' : trim(substr($name, strlen($first))));
		if ((strlen($first) < 32) && (strlen($last) < 32)) {
			return ['first' => $first, 'last' => $last];
		}

		// Take the last word as last name
		$first = ((strrpos($name, ' ') ? trim(substr($name, 0, strrpos($name, ' '))) : $name));
		$last = (($first === $name) ? '' : trim(substr($name, strlen($first))));

		if ((strlen($first) < 32) && (strlen($last) < 32)) {
			return ['first' => $first, 'last' => $last];
		}

		// Take the first 32 characters if there is no space in the first 32 characters
		if ((strpos($name, ' ') > 32) || (strpos($name, ' ') === false)) {
			$first = substr($name, 0, 32);
			$last = substr($name, 32);
			return ['first' => $first, 'last' => $last];
		}

		$first = trim(substr($name, 0, strrpos(substr($name, 0, 33), ' ')));
		$last = (($first === $name) ? '' : trim(substr($name, strlen($first))));

		// Check if the last name is longer than 32 characters
		if (strlen($last) > 32) {
			if (strpos($last, ' ') <= 32) {
				$last = trim(substr($last, 0, strrpos(substr($last, 0, 33), ' ')));
			} else {
				$last = substr($last, 0, 32);
			}
		}

		return ['first' => $first, 'last' => $last];
	}

	/**
	 * @brief Create profile data
	 *
	 * @param int $uid The user id
	 *
	 * @return array The profile data
	 */
	private static function createProfileData($uid)
	{
		$r = q(
			"SELECT `profile`.`uid` AS `profile_uid`, `profile`.* , `user`.*, `user`.`prvkey` AS `uprvkey`, `contact`.`addr`
			FROM `profile`
			INNER JOIN `user` ON `profile`.`uid` = `user`.`uid`
			INNER JOIN `contact` ON `profile`.`uid` = `contact`.`uid`
			WHERE `user`.`uid` = %d AND `profile`.`is-default` AND `contact`.`self` LIMIT 1",
			intval($uid)
		);

		if (!$r) {
			return [];
		}

		$profile = $r[0];
		$handle = $profile["addr"];

		$split_name = self::splitName($profile['name']);
		$first = $split_name['first'];
		$last = $split_name['last'];

		$large = System::baseUrl().'/photo/custom/300/'.$profile['uid'].'.jpg';
		$medium = System::baseUrl().'/photo/custom/100/'.$profile['uid'].'.jpg';
		$small = System::baseUrl().'/photo/custom/50/'  .$profile['uid'].'.jpg';
		$searchable = (($profile['publish'] && $profile['net-publish']) ? 'true' : 'false');

		$dob = null;
		$about = null;
		$location = null;
		$tags = null;
		if ($searchable === 'true') {
			$dob = '';

			if ($profile['dob'] && ($profile['dob'] > '0000-00-00')) {
				list($year, $month, $day) = sscanf($profile['dob'], '%4d-%2d-%2d');
				if ($year < 1004) {
					$year = 1004;
				}
				$dob = DateTimeFormat::utc($year . '-' . $month . '-'. $day, 'Y-m-d');
			}

			$about = $profile['about'];
			$about = strip_tags(BBCode::convert($about));

			$location = Profile::formatLocation($profile);
			$tags = '';
			if ($profile['pub_keywords']) {
				$kw = str_replace(',', ' ', $profile['pub_keywords']);
				$kw = str_replace('  ', ' ', $kw);
				$arr = explode(' ', $profile['pub_keywords']);
				if (count($arr)) {
					for ($x = 0; $x < 5; $x ++) {
						if (!empty($arr[$x])) {
							$tags .= '#'. trim($arr[$x]) .' ';
						}
					}
				}
			}
			$tags = trim($tags);
		}

		return ["author" => $handle,
				"first_name" => $first,
				"last_name" => $last,
				"image_url" => $large,
				"image_url_medium" => $medium,
				"image_url_small" => $small,
				"birthday" => $dob,
				"gender" => $profile['gender'],
				"bio" => $about,
				"location" => $location,
				"searchable" => $searchable,
				"nsfw" => "false",
				"tag_string" => $tags];
	}

	/**
	 * @brief Sends profile data
	 *
	 * @param int  $uid    The user id
	 * @param bool $recips optional, default false
	 * @return void
	 */
	public static function sendProfile($uid, $recips = false)
	{
		if (!$uid) {
			return;
		}

		$owner = User::getOwnerDataById($uid);
		if (!$owner) {
			return;
		}

		if (!$recips) {
			$recips = q(
				"SELECT `id`,`name`,`network`,`pubkey`,`notify` FROM `contact` WHERE `network` = '%s'
				AND `uid` = %d AND `rel` != %d",
				DBA::escape(Protocol::DIASPORA),
				intval($uid),
				intval(Contact::SHARING)
			);
		}

		if (!$recips) {
			return;
		}

		$message = self::createProfileData($uid);

		foreach ($recips as $recip) {
			logger("Send updated profile data for user ".$uid." to contact ".$recip["id"], LOGGER_DEBUG);
			self::buildAndTransmit($owner, $recip, "profile", $message, false, "", false);
		}
	}

	/**
	 * @brief Stores the signature for likes that are created on our system
	 *
	 * @param array $contact The contact array of the "like"
	 * @param int   $post_id The post id of the "like"
	 *
	 * @return bool Success
	 */
	public static function storeLikeSignature(array $contact, $post_id)
	{
		// Is the contact the owner? Then fetch the private key
		if (!$contact['self'] || ($contact['uid'] == 0)) {
			logger("No owner post, so not storing signature", LOGGER_DEBUG);
			return false;
		}

		$user = DBA::selectFirst('user', ['prvkey'], ['uid' => $contact["uid"]]);
		if (!DBA::isResult($user)) {
			return false;
		}

		$contact["uprvkey"] = $user['prvkey'];

		$item = Item::selectFirst([], ['id' => $post_id]);
		if (!DBA::isResult($item)) {
			return false;
		}

		if (!in_array($item["verb"], [ACTIVITY_LIKE, ACTIVITY_DISLIKE])) {
			return false;
		}

		$message = self::constructLike($item, $contact);
		if ($message === false) {
			return false;
		}

		$message["author_signature"] = self::signature($contact, $message);

		/*
		 * Now store the signature more flexible to dynamically support new fields.
		 * This will break Diaspora compatibility with Friendica versions prior to 3.5.
		 */
		DBA::insert('sign', ['iid' => $post_id, 'signed_text' => json_encode($message)]);

		logger('Stored diaspora like signature');
		return true;
	}

	/**
	 * @brief Stores the signature for comments that are created on our system
	 *
	 * @param array  $item       The item array of the comment
	 * @param array  $contact    The contact array of the item owner
	 * @param string $uprvkey    The private key of the sender
	 * @param int    $message_id The message id of the comment
	 *
	 * @return bool Success
	 */
	public static function storeCommentSignature(array $item, array $contact, $uprvkey, $message_id)
	{
		if ($uprvkey == "") {
			logger('No private key, so not storing comment signature', LOGGER_DEBUG);
			return false;
		}

		$contact["uprvkey"] = $uprvkey;

		$message = self::constructComment($item, $contact);
		if ($message === false) {
			return false;
		}

		$message["author_signature"] = self::signature($contact, $message);

		/*
		 * Now store the signature more flexible to dynamically support new fields.
		 * This will break Diaspora compatibility with Friendica versions prior to 3.5.
		 */
		DBA::insert('sign', ['iid' => $message_id, 'signed_text' => json_encode($message)]);

		logger('Stored diaspora comment signature');
		return true;
	}
}
