<?php

function xml($response, $tag = 'NODE') {
	if (empty($response)) {
		$response = array('response' => 0);
	}

	if (!is_array($response) && !is_object($response)) {
		$response = array('response' => $response);
	}

	return _xml($response, 'DATA', null, $tag);
}

/** 
 * xml2array() will convert the given XML text to an array in the XML structure. 
 * Link: http://www.bin-co.com/php/scripts/xml2array/ 
 * Arguments : $contents - The XML text 
 *                $get_attributes - 1 or 0. If this is 1 the function will get the attributes as well as the tag values - this results in a different array structure in the return value.
 *                $priority - Can be 'tag' or 'attribute'. This will change the way the resulting array sturcture. For 'tag', the tags are given more importance.
 * Return: The parsed XML in an array form. Use print_r() to see the resulting array structure. 
 * Examples: $array =  xml2array(file_get_contents('feed.xml')); 
 *              $array =  xml2array(file_get_contents('feed.xml', 1, 'attribute')); 
 */ 
function xml2array($contents, $get_attributes = 1, $priority = 'tag') {
	if (!$contents) return array();
	
	if (!function_exists('xml_parser_create')) {
		return array();
	}
	
	//Get the XML parser of PHP - PHP must have this module for the parser to work
	$parser = xml_parser_create('');
	xml_parser_set_option($parser, XML_OPTION_TARGET_ENCODING, "UTF-8"); # http://minutillo.com/steve/weblog/2004/6/17/php-xml-and-character-encodings-a-tale-of-sadness-rage-and-data-loss
	xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
	xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
	xml_parse_into_struct($parser, trim($contents), $xml_values);
	xml_parser_free($parser);
	
	if (!$xml_values) return;
	
	//Initializations
	$xml_array = array();
	$parents = array();
	$opened_tags = array();
	$arr = array();
	
	$current = &$xml_array; //Refference
	
	//Go through the tags.
	$repeated_tag_index = array();//Multiple tags with same name will be turned into an array
	foreach ($xml_values as $data) {
		unset($attributes,$value);//Remove existing values, or there will be trouble
		
		//This command will extract these variables into the foreach scope
		// tag(string), type(string), level(int), attributes(array).
		extract($data);//We could use the array by itself, but this cooler.
		
		$result = array();
		$attributes_data = array();
		
		if (isset($value)) {
			if ($priority == 'tag') $result = $value;
			else $result['value'] = $value; //Put the value in a assoc array if we are in the 'Attribute' mode
		}
		
		//Set the attributes too.
		if (isset($attributes) and $get_attributes) {
			foreach ($attributes as $attr => $val) {
				if ($priority == 'tag') $attributes_data[$attr] = $val;
				else $result['attr'][$attr] = $val; //Set all the attributes in a array called 'attr'
			}
		}
		
		//See tag status and do the needed.
		if ($type == "open") {//The starting of the tag '<tag>'
			$parent[$level-1] = &$current;
			if (!is_array($current) or (!in_array($tag, array_keys($current)))) { //Insert New tag
				$current[$tag] = $result;
				if ($attributes_data) $current[$tag. '_attr'] = $attributes_data;
				$repeated_tag_index[$tag.'_'.$level] = 1;
				
				$current = &$current[$tag];
			} else { //There was another element with the same tag name
				if (isset($current[$tag][0])) {//If there is a 0th element it is already an array
					$current[$tag][$repeated_tag_index[$tag.'_'.$level]] = $result;
					$repeated_tag_index[$tag.'_'.$level]++;
				} else {//This section will make the value an array if multiple tags with the same name appear together
					$current[$tag] = array($current[$tag],$result);//This will combine the existing item and the new item together to make an array
					$repeated_tag_index[$tag.'_'.$level] = 2;
					
					if (isset($current[$tag.'_attr'])) { //The attribute of the last(0th) tag must be moved as well
						$current[$tag]['0_attr'] = $current[$tag.'_attr'];
						unset($current[$tag.'_attr']);
					}
				}
				
				$last_item_index = $repeated_tag_index[$tag.'_'.$level]-1;
				$current = &$current[$tag][$last_item_index];
			}
		} elseif ($type == "complete") { //Tags that ends in 1 line '<tag />'
			//See if the key is already taken.
			if (!isset($current[$tag])) { //New Key
				$current[$tag] = $result;
				$repeated_tag_index[$tag.'_'.$level] = 1;
				if ($priority == 'tag' and $attributes_data) $current[$tag. '_attr'] = $attributes_data;
			} else { //If taken, put all things inside a list(array)
				if(isset($current[$tag][0]) and is_array($current[$tag])) {//If it is already an array...
					// ...push the new element into that array.
					$current[$tag][$repeated_tag_index[$tag.'_'.$level]] = $result;
					
					if ($priority == 'tag' and $get_attributes and $attributes_data) {
						$current[$tag][$repeated_tag_index[$tag.'_'.$level] . '_attr'] = $attributes_data;
					}
					
					$repeated_tag_index[$tag.'_'.$level]++;
				} else { //If it is not an array...
					$current[$tag] = array($current[$tag],$result); //...Make it an array using using the existing value and the new value
					$repeated_tag_index[$tag.'_'.$level] = 1;
					
					if ($priority == 'tag' and $get_attributes) {
						if(isset($current[$tag.'_attr'])) { //The attribute of the last(0th) tag must be moved as well
							$current[$tag]['0_attr'] = $current[$tag.'_attr'];
							unset($current[$tag.'_attr']);
						}
						
						if ($attributes_data) {
							$current[$tag][$repeated_tag_index[$tag.'_'.$level] . '_attr'] = $attributes_data;
						}
					}
					
					$repeated_tag_index[$tag.'_'.$level]++; //0 and 1 index is already taken
				}
			}
		} elseif($type == 'close') { //End of tag '</tag>'
			$current = &$parent[$level-1];
		}
	}
	
	return($xml_array);
}

/**
 * The main function for converting to an XML document.
 * Pass in a multi dimensional array and this recrusively loops through and builds up an XML document.
 *
 * @param array $data
 * @param string $rootNodeName - what you want the root node to be - defaultsto data.
 * @param SimpleXMLElement $xml - should only be used recursively
 * @return string XML
 */
function _xml($data, $rootNodeName = 'DATA', $xml = null, $tagnode = 'NODE') {
	// turn off compatibility mode as simple xml throws a wobbly if you don't.
	if (ini_get('zend.ze1_compatibility_mode') == 1) {
		ini_set ('zend.ze1_compatibility_mode', 0);
	}

	if ($xml == null) {
		$xml = simplexml_load_string("<?xml version='1.0' encoding='utf-8'?><$rootNodeName />");
	}

	// loop through the data passed in.
	foreach ($data as $key => $value) {
		// no numeric keys in our xml please!
		if (is_numeric($key)) {
			// make string key...
			$key = $tagnode . '_' . (string) $key;
		}

		// replace anything not alpha numeric
		$key = strtoupper(preg_replace('/[^a-z]/i', '', $key));

		// if there is another array found recrusively call this function
		if (is_array($value)) {
			$node = $xml->addChild($key);
			// recrusive call.
			_xml($value, $rootNodeName, $node, $tagnode);
		} else {
			// add single node.
			$value = htmlentities($value);
			$xml->addChild($key,$value);
		}
	}

	// pass back as string. or simple xml object if you want!
	return $xml->asXML();
}

?>