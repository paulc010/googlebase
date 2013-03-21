<?php

if (!defined('_PS_VERSION_'))
	exit;
	
class GoogleBase extends Module
{
	private $_html = '';
	private $_postErrors = array();
	private $_mod_warnings;
	private $_mod_errors;
	
	private $xml_description;
	private $id_lang;
	private $languages;
	private $lang_iso;
	private $id_currency;
	private $currencies;
	private $country;
	private $target_country;
	private $default_tax;
	private $ignore_tax;
	private $ignore_shipping;
	private $gtin_field;
	private $use_supplier;
	
	/* Maintain to enhance error messages */
	private $current_product;
	
	/*
	*
	* Module housekeeping and settings
	*/
	
	/**
	* GoogleBase module constructor
	*
	* Performs standard module initialisation
	* 
	*/
	public function __construct()
	{
		$this->_mod_warnings = array();
		$this->_mod_errors = array();
		
		$this->name = 'googlebase';
		$this->tab = 'advertising_marketing';
		$this->author = 'eCartService.net';
		$this->version = '0.9';
		$this->need_instance = 0;
		
		parent::__construct();
		
		// Set default config values if they don't already exist (here for compatibility in case the user doesn't uninstall/install at upgrade)
		// Also set global "macro" data for the feed and check for store configuration changes
		if ($this->isInstalled($this->name))
		{	
			// Cleanup old configuration values that are deprecated
			if (Configuration::get($this->name.'_condition'))
				Configuration::deleteByName($this->name.'_condition');
			if (Configuration::get($this->name.'_domain'))
				Configuration::deleteByName($this->name.'_domain');
			if (Configuration::get($this->name.'_psdir'))
				Configuration::deleteByName($this->name.'_psdir');
				
			// Set up meaningful defaults
			$this->_setDefaults();
		}

		$this->displayName = $this->l('[BETA]Google Base Feed Products (Prestashop 1.5.3+ only)');
		$this->description = $this->l('Generate your products feed for Google Products Search. www.ecartservice.net');
	}

	/**
	* Module installer
	*
	* @return bool Success/failure
	*/
	public function install()
	{
		$this->_setDefaults();
		return parent::install();
	}

	/**
	* Initialise the configuration variables and transient class data 
	*
	*/
	private function _setDefaults()
	{
		if (!Configuration::get($this->name.'_description'))
			Configuration::updateValue($this->name.'_description', '****Type some text to describe your shop before generating your first feed****');
		if (!Configuration::get($this->name.'_lang'))
			Configuration::updateValue($this->name.'_lang', $this->context->cookie->id_lang);
		if (!Configuration::get($this->name.'_gtin'))
			Configuration::updateValue($this->name.'_gtin', 'ean13');
		if (!Configuration::get($this->name.'_use_supplier'))
			Configuration::updateValue($this->name.'_use_supplier', 'on');
		if (!Configuration::get($this->name.'_currency'))
			Configuration::updateValue($this->name.'_currency', (int)Configuration::get('PS_CURRENCY_DEFAULT'));
		if (!Configuration::get($this->name.'_condition'))
			Configuration::updateValue($this->name.'_condition', 'new');
		if (!Configuration::get($this->name.'_country'))
			Configuration::updateValue($this->name.'_country', 'United Kingdom');
		if (!Configuration::get($this->name.'_ignore_tax'))
			Configuration::updateValue($this->name.'_ignore_tax', 0);
		if (!Configuration::get($this->name.'_ignore_shipping'))
			Configuration::updateValue($this->name.'_ignore_shipping', 0);
		
		$this->_getGlobals();
	
		if (!Configuration::get($this->name.'_filepath'))
			Configuration::updateValue($this->name.'_filepath', addslashes($this->_defaultOutputFile()));
	
	}

	/**
	* Initialise global environment settings
	*
	* At various points the feed required language and currency settings.
	* These may obviously be changed outwith the module so we need to do a check
	* and reset as appropriate. Also initialises language and currency lists for the config screen.
	*
	* @param	string	Target directory for the generated HTML Files
	*/
	private function _getGlobals()
	{
		$this->xml_description = Configuration::get($this->name.'_description');
	  
		$this->languages = $this->getLanguages();
		$this->id_lang = intval(Configuration::get($this->name.'_lang'));
		$this->lang_iso = strtolower(Language::getIsoById($this->id_lang));
		if (!isset($this->languages[$this->id_lang]))
		{
			Configuration::updateValue($this->name.'_lang', (int)$this->context->cookie->id_lang);
			$this->id_lang = (int)$this->context->cookie->id_lang;
			$this->lang_iso = strtolower(Language::getIsoById($this->id_lang));
			$this->_mod_warnings[] = $this->l('Language configuration is invalid - reset to default.');
		}
	  
		$this->gtin_field = Configuration::get($this->name.'_gtin');
	  
		$this->use_supplier = Configuration::get($this->name.'_use_supplier');
		// Fix old setting method
		if ($this->use_supplier == '1')
		{
			Configuration::updateValue($this->name.'_use_supplier', 'on');
			$this->use_supplier = 'on';
		}
	  
		$this->currencies = $this->getCurrencies();
		$this->id_currency = intval(Configuration::get($this->name.'_currency'));
		if (!isset($this->currencies[$this->id_currency]))
		{
			Configuration::updateValue($this->name.'_currency', (int)Configuration::get('PS_CURRENCY_DEFAULT'));
			$this->id_currency = (int)Configuration::get('PS_CURRENCY_DEFAULT');
			$this->_mod_warnings[] = $this->l('Currency configuration is invalid - reset to default.');
		}
	  
		$this->default_condition = Configuration::get($this->name.'_condition');

		$this->country = Configuration::get($this->name.'_country');
		$id_country = Country::getIdByName((int)$this->id_lang, $this->country);
		if (!$id_country)
			die (Tools::displayError('Failed to find target country: '.$this->country));
		$this->target_country = new Country($id_country);
		if (!Validate::isLoadedObject($this->target_country))
			die (Tools::displayError('Can\'t instantiate target country object '.$this->target_country));
		$this->default_tax = (float)Configuration::get($this->name.'_default_tax');
		$this->ignore_tax = Configuration::get($this->name.'_ignore_tax');
		$this->ignore_shipping = Configuration::get($this->name.'_ignore_shipping');
	}
	
	/**
	* Get installed (and active) currencies
	*
	* @param	book 	$object	Return as an object (true) or array (false)
	* @param	int			$active	Return only active (true)
	* @return		array							Currencies as objects or array
	*/
	public function getCurrencies($object = true, $active = 1)
	{
		$tab = Db::getInstance()->ExecuteS('
					SELECT *
					FROM `'._DB_PREFIX_.'currency`
					WHERE `deleted` = 0
					'.($active == 1 ? 'AND `active` = 1' : '').'
					ORDER BY `name` ASC');
  
		if ($object)
			foreach ($tab as $key => $currency)
				$tab[$currency['id_currency']] = Currency::getCurrencyInstance($currency['id_currency']);
				
		return $tab;
	}
	
	/**
	* Get active languages for store
	*
	* @return		array							Installed and active languages	
	*
	*/
	public function getLanguages()
	{
		$languages = array();
	
		$result = Db::getInstance()->ExecuteS('
						SELECT `id_lang`, `name`, `iso_code`, `active`
						FROM `'._DB_PREFIX_.'lang` WHERE `active` = \'1\'');
	
		foreach ($result as $row)
			  $languages[(int)($row['id_lang'])] = array('id_lang' => (int)($row['id_lang']), 'name' => $row['name'], 'iso_code' => $row['iso_code'], 'active' => (int)($row['active']));
		
		return $languages;
	}

	/*
	 * File handling and directory management
	 */
	
	/**
	* Shorthand to work out the __PS_BASE_URI__ directory
	*
	* @return string The real path of the base installation directory
	*/
	private function _directory()
	{
		return realpath(dirname(__FILE__).'/../../'); // move up to the __PS_BASE_URI__ directory
	}

	/**
	* Fix windows filenames
	*
	* When used on a Windows server the filenames get mangled.
	* This is just a hack to undo the changes to the config variable.
	*
	* @param	string	$file		filename to "fix"
	* @return		string	The fixed filename
	*/
	private function _winFixFilename($file)
	{
		return str_replace('\\\\', '\\', $file);
	}

	/**
	* Determine a sane default for the output file
	*
	* @return		string		The defult location of the output file
	*/
	private function _defaultOutputFile()
	{
		// PHP on windows seems to return a trailing '\' where as on unix it doesn't
		$output_dir = $this->_directory();
		$dir_separator = '/';

		// If there's a windows directory separator on the end,
		// then don't add the unix one too when building the final output file
		if (substr($output_dir, -1, 1) == '\\')
			$dir_separator = '';

		$output_file = $output_dir.$dir_separator.$this->lang_iso.'_'.strtolower($this->currencies[$this->id_currency]->iso_code).'_googlebase.xml';
		return $output_file;
	}
	
		/**
	* Create a url to access the generated feed
	*
	* @return		string	The url to the feed file
	*/
	private function _file_url()
	{
		$filename = $this->_winFixFilename(Configuration::get($this->name.'_filepath'));
		$file = str_replace($this->_directory(), '', $filename);
  
		$separator = '';
  
		if (substr($file, 0, 1) == '\\')
			substr_replace($file, '/', 0, 1);
  
		if (substr($file, 0, 1) != '/')
			$separator = '/';
  
		return 'http://'.$_SERVER['HTTP_HOST'].$separator.$file;
	}
	
	/**
	* Write arbitrary text out to the feed file
	*
	* Multiple line detailed description.
	* The handling of line breaks and HTML is up to the renderer.
	* Order: short description - detailed description - doc tags.
	*
	* @param	string	$str Text to write to the feed file
	*/
	private function _addToFeed($str)
	{
		$filename = $this->_winFixFilename(Configuration::get($this->name.'_filepath'));
		if (file_exists($filename))
		{
			$fp = fopen($filename, 'ab');
			fwrite($fp, $str, strlen($str));
			fclose($fp);
		}
	}
	
	/*
	 * Feed generation and data properties
	 */
	
	/**
	* Public create feed method for cron script
	*
	*/
	public function do_crontask()
	{
		$this->_postProcess(true);
	}
	
	/**
	* Create the product feed
	*
	* The main core of the module, responsible for creating the feed and associated product entries
	*/	
	private function _postProcess($cron = false)
	{
		$products = Product::getProducts($this->id_lang, 0, null, 'id_product', 'ASC');
  
		if ($products)
		{
			if (!$fp = fopen($this->_winFixFilename(Configuration::get($this->name.'_filepath')), 'w'))
			{
				$this->_mod_errors[] = $this->l('Error writing to feed file.');
				return;
			}
			fclose($fp);
  
			// Required headers
			$items = '<?xml version="1.0"?>'."\n"
				  .'<rss version ="2.0" xmlns:g="http://base.google.com/ns/1.0">'."\n"
				  .'<channel>'."\n"
				  .'<title>Google Base feed for '.$_SERVER['HTTP_HOST'].'</title>'."\n"
					.'<link>http://'.$_SERVER['HTTP_HOST'].'/</link>'."\n"
				  .'<description>'.$this->_xmlentities($this->xml_description).'</description>'."\n";
		
			foreach ($products as $product)
			{
			 	if ($product['active'])
				{
					//echo '<pre>'.print_r($product, true).'</pre>';
					//echo '<h2>Product Id: '.$product['id_product'].'</h2><pre>'.print_r(Product::getProductAttributesIds($product['id_product']), true).'</pre>';
					
					// We need to check whether we need to loop for product variants
					$combinations = Product::getProductAttributesIds($product['id_product']);
					if (empty($combinations))
					{
						$items .= "<item>\n";
						$items .= $this->_processProduct($product);
						$items .= "</item>\n\n";
					}
					else
						foreach ($combinations as $combination)
						{
							$items .= "<item>\n";
							$items .= $this->_processProduct($product, $combination['id_product_attribute']);
							$items .= "</item>\n\n";
						}
				}
			}
			$this->_addToFeed( "$items</channel>\n</rss>\n" );
		}
  
		if (!$cron)
		{
			$res = file_exists($this->_winFixFilename(Configuration::get($this->name.'_filepath')));
			if ($res)
				$this->_html .= '<h3 class="conf confirm" style="margin-bottom: 20px">'.$this->l('Feed file successfully generated').'</h3>';
			else
				$this->_mod_errors[] = $this->l('Error while creating feed file');
		}
	}

	/**
	* Process a single product (or variant)
	*
	* @param	array	$product										The data associated with the product from the database
	* @param	int			$id_product_attribute		A variant id or 0 if product has no combinations
	*/	
	private function _processProduct($product, $id_product_attribute = 0)
	{
		$item_data = '';
		// Maintain a copy of the current product id for more meaningful error messages
		$this->current_product = $product['id_product'];
		if ($id_product_attribute)
			$variant = new Combination($id_product_attribute);
		else
			$variant = '';

		$product_link = $this->_getProductLink($product, $variant);
		$image_links = $this->_getImageLinks($product);
		
		// Reference page: http://www.google.com/support/merchants/bin/answer.py?answer=188494
		
		// 1. Basic Product Information
		
		$item_data .= $this->_xmlElement('g:id', 'pc'.$this->lang_iso.'-'.$product['id_product'].'-'.$id_product_attribute);
		if (is_object($variant))
		{
			$item_data .= $this->_xmlElement('g:item_group_id', 'pc'.$this->lang_iso.'-'.$product['id_product']);
			$variant_labels = $variant->getAttributesName($this->id_lang);
			$variant_name = $product['name'].' (';
			foreach ($variant_labels as $label)
				$variant_name .= ' '.$label['name'];
				
			$variant_name = $variant_name.' )';
			$item_data .= $this->_xmlElement('title', $variant_name, true);
		} else
			$item_data .= $this->_xmlElement('title', $product['name'], true);
		
		// Try our best to get a decent description
		$description = trim(strip_tags(strlen($product['description_short']) ? $product['description_short'] :  $product['description'] ));
		// Remove invalid characters that may have been inserted incorrectly
		$description = preg_replace('/[^\x0A\x0D\x20-\x7F]/', '', $description);
		$item_data .= $this->_xmlElement('description', '<![CDATA['.$description.']]>');
		
		// google product category <g:google_product_category /> - Google's category of the item (TODO: support this!)
		
		$item_data .= $this->_xmlElement('g:product_type', $this->getPath($product['id_category_default']));
		$item_data .= $this->_xmlElement('link', $product_link, true);
		if ($image_links[0]['valid'] == 1)
			$item_data .= $this->_xmlElement('g:image_link', $image_links[0]['link'], true);
		if ($image_links[1]['valid'] == 1)
			$item_data .= $this->_xmlElement('g:additional_image_link', $image_links[1]['link'], true);
		
		$item_data .= $this->_xmlElement('g:condition', $this->_getCondition($product['condition']));
		
		// 2. Availability & Price
		$item_data .= $this->_xmlElement('g:availability', $this->_getAvailability($product, $id_product_attribute));
		// Price is WITHOUT any reduction
		$price = $this->_getPrice($product['id_product'], $id_product_attribute);
		$item_data .= $this->_xmlElement('g:price', $price);
		// TODO: If there is an active discount, then include it
		$price_with_reduction = $this->_getSalePrice($product['id_product'], $id_product_attribute);
		if ($price_with_reduction !== $price)
			$item_data .= $this->_xmlElement('g:sale_price', $price_with_reduction);
		/*
		// Effective date is in ISO8601 format TODO: Support "sales" somehow - need a way of returning "expiry date" for the reduction
		$items .= "<g:sale_price_effective_date>".Product::getPriceStatic(intval($product['id_product']))."</g:sale_price_effective_date>\n";
		*/
		
		// 3. Unique Product Identifiers
		if ($product['manufacturer_name'])
			$item_data .= $this->_xmlElement('g:brand', $product['manufacturer_name'], true);
		
		// gtin value
		$item_data .= $this->_xmlElement('g:gtin', $this->_getGtinValue($product, $variant));
		
		if ($this->use_supplier == 'on')
			if (isset($product['id_supplier']) && !empty($product['id_supplier']) || (is_object($variant) && !empty($variant->supplier_reference)))
			
				if (!is_object($variant))
					$item_data .= $this->_xmlElement('g:mpn', ProductSupplier::getProductSupplierReference($product['id_product'], 0, $product['id_supplier']));
				else
					$item_data .= $this->_xmlElement('g:mpn', $variant->supplier_reference);
			
		elseif (!is_object($variant))
				$item_data .= $this->_xmlElement('g:mpn', $product['reference']);
			else
				$item_data .= $this->_xmlElement('g:mpn', $variant->reference);
				
		// 6. Tax & Shipping
    if ($this->country == 'United States' && !$this->ignore_tax)
      $item_data .= $this->_xmlTaxGroups($product);

    if (!$this->ignore_shipping)
      $item_data .= $this->_xmlShippingGroups($product);

    $item_data .= $this->_xmlElement('g:shipping_weight', $product['weight'] ? $product['weight'].' '.Configuration::get('PS_WEIGHT_UNIT') : 0);
		
		// 7. Nearby Stores (US & UK only)
	if (($this->country == 'United States' || $this->country == 'United Kingdom'))
			$item_data .= $this->_xmlElement('g:online_only', $product['online_only'] == 1 ? 'y' : 'n');
		
		return $item_data;
	}

	/**
	* Produce a human readable "breadcrumb" path
	*
	* Multiple line detailed description.
	* The handling of line breaks and HTML is up to the renderer.
	* Order: short description - detailed description - doc tags.
	*
	* @param	int	 $id_cat Id of category to process
	* @return		string				The path
	*/
	static private $cacheCatPath = array();
	public function getPath($id_cat)
	{
		if (!isset(self::$cacheCatPath[$id_cat]))
			self::$cacheCatPath[$id_cat] = $this->_getPath($id_cat);
	  
		return self::$cacheCatPath[$id_cat];
	}
	
	/**
	* Fetch the Global Trade Identifier as configured for the module
	*
	* @param	array		$product	The data associated with the product from the database
	* @param	object	$variant		Specific data associated with a product variant
	* @return		mixed								The value to use
	*/	
	private function _getGtinValue($product, $variant = null)
	{
		if (!is_object($variant))
		{
			$ean13 = $product['ean13'];
			$upc = $product['upc'];
		} else {
			$ean13 = $variant->ean13;
			$upc = $variant->upc;
		}
		
		$gtin = '';
	
		switch ($this->gtin_field)
		{
			case 'isbn10':
			case 'isbn13':
			case 'jan8':
			case 'jan13':
			case 'ean13':
				$gtin = $ean13;
			break;
			case 'upc':
				$gtin = $upc;
			break;
			case 'none':
				$gtin = '';
			break;
		}
		return $gtin;
	}
	
	/**
	* Wrapper to translate condition data element
	*
	* @param	string	$condition	The text for the condition
	* @return		string									The translated condition
	*/
	private function _getCondition($condition)
	{
		switch ($condition)
		{
			case 'new':
				$condition = $this->l('new');
			break;
			case 'used':
				$condition = $this->l('used');
			break;
			case 'refurbished':
				$condition = $this->l('refurbished');
			break;
		}
		return $condition;
	}
	
	/**
	* Check product availability in store
	*
	* @param	array	$product									The product data retrieved from the database
	* @param	int			$id_product_attribute The attribute (variant) id
	* @return 	string															Translated stock status
	*/
	private function _getAvailability($product, $id_product_attribute = 0)
	{
		if (StockAvailable::getQuantityAvailableByProduct($product['id_product'], $id_product_attribute) > 0)
      return $this->l('in stock');
    else if (self::_checkQty($product, $id_product_attribute, 1))
      return $this->l('available for order');

    return $this->l('out of stock');
	}
	
	/**
	* Get the standard price of a specific product or variant
	*
	* @param	int			The product id
	* @param	int			The attribute (variant) id
	* @return		float	The price
	*/
	private function _getPrice($id_product, $id_product_attrib = null)
	{
		$taxCalculationMethod = Group::getDefaultPriceDisplayMethod();
		if (($this->country == 'United States' && !$force_tax) || $taxCalculationMethod == PS_TAX_EXC)
      $use_tax = false;
    else
      $use_tax = true;

    $price = number_format(Tools::convertPrice(Product::getPriceStatic(intval($id_product), $use_tax, $id_product_attrib, 6, null, false, false), $this->currencies[$this->id_currency]), 2, '.', '');
		
		return $price.' '.$this->currencies[$this->id_currency]->iso_code;
	}
	
	/**
	* Get the sale price of a specific product or variant
	*
	* @param	int			The product id
	* @param	int			The attribute (variant) id
	* @return		float	The sale price
	*/
	private function _getSalePrice($id_product, $id_product_attrib = null)
	{
		$taxCalculationMethod = Group::getDefaultPriceDisplayMethod();
		if (($this->country == 'United States' && !$force_tax) || $taxCalculationMethod == PS_TAX_EXC)
      $use_tax = false;
    else
      $use_tax = true;

    $price = number_format(Tools::convertPrice(Product::getPriceStatic(intval($id_product), $use_tax, $id_product_attrib, 6), $this->currencies[$this->id_currency]), 2, '.', '');
		
		return $price.' '.$this->currencies[$this->id_currency]->iso_code;
	}
	
	/**
	* Get links to images associated with the product or variant
	*
	* @param	array		$product	The data associated with the product from the database
	* @param	object	$variant		Specific data associated with a product variant
	* @return array									Array of image links
	*/
	private function _getImageLinks($product, $variant = null)
	{
		// TODO: use variant image if available
		// $variant->getCombinationImages($id_lang);
		$image_data = array(array('link' => '', 'valid' => 0), array('link' => '', 'valid' => 0));
		$images = Image::getImages($this->id_lang, $product['id_product']);
		
		if (isset($images[0]))
		{
			$image_data[0]['link'] = $this->context->link->getImageLink($product['link_rewrite'], (int)$product['id_product'].'-'.(int)$images[0]['id_image']);
			$image_data[0]['valid'] = 1;
		}
		if (isset($images[1]))
		{
			$image_data[1]['link'] = $this->context->link->getImageLink($product['link_rewrite'], (int)$product['id_product'].'-'.(int)$images[1]['id_image']);
			$image_data[1]['valid'] = 1;
		}

		return $image_data;
	}
	
	/**
	* Get product link
	*
	* @param	array		$product	The data associated with the product from the database
	* @param	object	$variant		Specific data associated with a product variant
	* @return		string								The product url
	*/
	private function _getProductLink($product, $variant = null)
	{
		$variant_anchor = '';
		if (is_object($variant))
		{
			$product_object = new Product($product['id_product']);
			$variant_anchor = $product_object->getAnchor($variant->id);
		}
		return $this->context->link->getProductLink($product, null, null, null, (int)$this->id_lang).$variant_anchor;
	}
	
	private function _xmlTaxGroups($id_product)
	{
		  $states = array();
		  $counties = array();
		  $tax_groups = '';
		  
		  if (Country::containsStates($this->target_country->id))
			{
			  // Country default
			  $tax_groups .= $this->_xmlTaxGroup((int)$id_product);
			  $states = State::getStatesByIdCountry($this->target_country->id);
		  
			  foreach ($states as $state)
				{
				  // State default
				  $tax_groups .= $this->_xmlTaxGroup((int)$id_product, $state);
				  if (State::hasCounties($state['id_state']))
					{
					  $counties = County::getCounties($state['id_state']);
					  foreach ($counties as $county)
					  {
						// County specific
						$tax_groups .= $this->_xmlTaxGroup((int)$id_product, $state, $county);
					  }
				  }
			  }
		  } else
			  $tax_groups .= $this->_xmlTaxGroup((int)$id_product);
		  
		  return $tax_groups;
	}
  
	private function _xmlTaxGroup($id_product, $state = null, $county = null)
	{
		  $group = "<g:tax>\n";
		  $group .= $this->_xmlElement('g:country', $this->target_country->iso_code);
		  if (is_array($state) && isset($state['iso_code']) && isset($state['id_state']))
			{
			  if (is_array($county) && isset($county['name']) && isset($county['id_county']))
				  $group .= $this->_xmlElement('g:region', $county['name']);
			  else
				   $group .= $this->_xmlElement('g:region', $state['iso_code']);
		  }
		  $rate = Tax::getProductTaxRateViaRules((int)$id_product, (int)$this->target_country->id,
												  (int)isset($state['id_state']) ? $state['id_state'] : 0,
												  (int)isset($county['id_county']) ? $county['id_county'] : 0);
		  $group .= $this->_xmlElement('g:rate', $rate);
		  $group .= "</g:tax>\n";
		  
		  // Omit tax group if it matches the Merchant Center default
		  if ((float)$this->default_tax && ($rate == $this->default_tax))
			   $group = '';
		  return $group;
	  }
  
	private function _xmlShippingGroups($product)
	{
		$states = array();
		$shipping_groups = '';
				
		if (Country::containsStates($this->target_country->id))
		{
			// Country default
			$shipping_groups .= $this->_xmlShippingCarriers($product);
			$states = State::getStatesByIdCountry($this->target_country->id);
		   
			 foreach ($states as $state)
				$shipping_groups .= $this->_xmlShippingCarriers($product, $state);

		} else
			$shipping_groups .= $this->_xmlShippingCarriers($product);
		
		return $shipping_groups;
	}
  
	private static $cacheZoneCarriers = array();
	private function _zoneCarriers($id_zone)
	{
		$carrier_objects = array();
	
		if (!isset(self::$cacheZoneCarriers[$id_zone]))
		{
			$carriers = Carrier::getCarriers($this->id_lang, true, false, $id_zone, null, 5);
			foreach ($carriers as $k => $row)
			{
				$carrier = new Carrier((int)$row['id_carrier'], $this->id_lang);
				$shippingMethod = $carrier->getShippingMethod();
				
				// Get only carriers that are compliant with shipping method
				if ($shippingMethod != Carrier::SHIPPING_METHOD_FREE)
					if (($shippingMethod == Carrier::SHIPPING_METHOD_WEIGHT && $carrier->getMaxDeliveryPriceByWeight($id_zone) === false)
					|| ($shippingMethod == Carrier::SHIPPING_METHOD_PRICE && $carrier->getMaxDeliveryPriceByPrice($id_zone) === false))
					{
						unset($carriers[$k]);
						continue;
					}
				$carrier_objects[] = $carrier;
			}
			self::$cacheZoneCarriers[$id_zone] = $carrier_objects;
		}
		return self::$cacheZoneCarriers[$id_zone];
	}
  
	private function _xmlShippingCarriers($product, $state = null)
	{
		$groups = '';
	
		if ($state)
		{
			// Only generate state-specific shipping if it is different from the target country zone
			if ($state['id_zone'] !== $this->target_country->id_zone)
			  $id_zone = $state['id_zone'];
			else
			  return '';
		}
		else
			$id_zone = $this->target_country->id_zone;
	
		$carriers = $this->_zoneCarriers($id_zone);
		if (!$carriers)
			die (Tools::displayError('Failed to find any valid Carriers for '.$this->country.' Zone = '.$id_zone));
	
		foreach ($carriers as $carrier)
			$groups .= $this->_xmlShippingGroup($product, $carrier, $id_zone, $state);

		return $groups;
	}
  
	private function _xmlShippingGroup($product, $carrier, $id_zone, $state = null)
	{
		if (!Validate::isLoadedObject($carrier))
			die(Tools::displayError('Fatal error: "no default carrier"'));
		
		$group = "<g:shipping>\n";
		$group .= $this->_xmlElement('g:country', $this->target_country->iso_code);
		if (is_array($state) && isset($state['iso_code']) && isset($state['id_state']))
			$group .= $this->_xmlElement('g:region', $state['iso_code']);

		$price = $this->getProductShippingCost($product, $carrier, $id_zone); // Calculate price
		if ($price === false)
			return '';
		$group .= $this->_xmlElement('g:service', $carrier->delay); // Service class or delivery speed
		$group .= $this->_xmlElement('g:price', $price, false, true); // 0 could be valid for free shipping
	
		$group .= "</g:shipping>\n";
	
		return $group;
	}
  
	private function getProductShippingCost($product, $carrier, $id_zone, $useTax = true)
	{
		// Order total in default currency without fees
		$order_total = $product['price'];

		// Start with shipping cost at 0
		$shipping_cost = 0;

		if (!Validate::isLoadedObject($carrier))
			die(Tools::displayError('Fatal error: "no default carrier"'));

		if (!$carrier->active)
			return $shipping_cost;

		// Free fees if free carrier
		if ($carrier->is_free == 1)
			return 0;

		// Select carrier tax
		if ($useTax && !Tax::excludeTaxeOption())
			 $carrierTax = Tax::getCarrierTaxRate((int)$carrier->id);

		$configuration = Configuration::getMultiple(array('PS_SHIPPING_FREE_PRICE', 'PS_SHIPPING_HANDLING', 'PS_SHIPPING_METHOD', 'PS_SHIPPING_FREE_WEIGHT'));
		// Free fees
		$free_fees_price = 0;
		if (isset($configuration['PS_SHIPPING_FREE_PRICE']))
			$free_fees_price = Tools::convertPrice((float)($configuration['PS_SHIPPING_FREE_PRICE']), Currency::getCurrencyInstance((int)($this->id_currency)));

		if ($order_total >= (float)($free_fees_price) && (float)($free_fees_price) > 0)
			return $shipping_cost;
		if (isset($configuration['PS_SHIPPING_FREE_WEIGHT']) && $product['weight'] >= (float)($configuration['PS_SHIPPING_FREE_WEIGHT']) && (float)($configuration['PS_SHIPPING_FREE_WEIGHT']) > 0)
			return $shipping_cost;

		// Get shipping cost using correct method
		if ($carrier->range_behavior)
		{
			if (($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_WEIGHT && (!Carrier::checkDeliveryPriceByWeight($carrier->id, $this->getTotalWeight(), $id_zone)))
					|| ($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_PRICE && (!Carrier::checkDeliveryPriceByPrice($carrier->id, $this->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING), $id_zone, (int)($this->id_currency)))))
					$shipping_cost += 0;
				else {
						if ($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_WEIGHT)
							$shipping_cost += $carrier->getDeliveryPriceByWeight($product['weight'], $id_zone);
						else // by price
							$shipping_cost += $carrier->getDeliveryPriceByPrice($order_total, $id_zone, (int)($this->id_currency));
					 }
		} else {
			if ($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_WEIGHT)
				$shipping_cost += $carrier->getDeliveryPriceByWeight($product['weight'], $id_zone);
			else
				$shipping_cost += $carrier->getDeliveryPriceByPrice($order_total, $id_zone, (int)($this->id_currency));

		}
		// Adding handling charges
		if (isset($configuration['PS_SHIPPING_HANDLING']) && $carrier->shipping_handling)
			$shipping_cost += (float)($configuration['PS_SHIPPING_HANDLING']);

		$shipping_cost = Tools::convertPrice($shipping_cost, Currency::getCurrencyInstance((int)($this->id_currency)));

		// Additional Shipping Cost per product
		$shipping_cost += $product['additional_shipping_cost'];

		//get external shipping cost from module
		if ($carrier->shipping_external)
		{
			$moduleName = $carrier->external_module_name;
			$module = Module::getInstanceByName($moduleName);

			if (Validate::isLoadedObject($module))
			{
				if (array_key_exists('id_carrier', $module))
					$module->id_carrier = $carrier->id;
				if ($carrier->need_range)
					$shipping_cost = $module->getOrderShippingCost($this, $shipping_cost);
				else
					$shipping_cost = $module->getOrderShippingCostExternal($this);

				// Check if carrier is available
				if ($shipping_cost === false)
					return false;
			}
			else
				return false;
		}

		// Apply tax
		if (isset($carrierTax))
			$shipping_cost *= 1 + ($carrierTax / 100);

		return number_format((float)($shipping_cost), 2, '.', '').' '.$this->currencies[$this->id_currency]->iso_code;
	}
	
	/*
	 * Module Configuration and settings
	 */
	
	/**
	* Display details of generated feed in configure screen
	*
	* Adds html to the output variable
	*/
	private function _displayFeed()
	{
		$filename = $this->_winFixFilename(Configuration::get($this->name.'_filepath'));
		if (file_exists($filename))
		{
			$this->_html .= '<fieldset><legend><img src="../img/admin/enabled.gif" alt="" class="middle" />'.$this->l('Feed Generated').'</legend>';
			if (strpos($filename, $this->_directory()) === false)
				$this->_html .= '<p>'.$this->l('Your Google Base feed file is available via ftp as the following:').' <b>'.$filename.'</b></p><br />';
			else
				$this->_html .= '<p>'.$this->l('Your Google Base feed file is online at the following address:').' <a href="'.$this->_file_url().'"><b>'.$this->_file_url().'</b></a></p><br />';
			
			$this->_html .= $this->l('Last Updated:').' <b>'.date('m.d.y G:i:s', filemtime($filename)).'</b><br />';
			$this->_html .= '</fieldset>';
		} else {
			$this->_html .= '<fieldset><legend><img src="../img/admin/delete.gif" alt="" class="middle" />'.$this->l('No Feed Generated').'</legend>';
			$this->_html .= '<br /><h3 class="alert error" style="margin-bottom: 20px">No feed file has been generated at this location yet!</h3>';
			$this->_html .= '</fieldset>';
		}
	}
	
	/**
	* Display the configuration form
	*
	* Adds html to the output variable 
	*/
	private function _displayForm()
	{
		$this->use_supplier = Configuration::get($this->name.'_use_supplier', 'on');
		$this->gtin_field = Tools::getValue('gtin', Configuration::get($this->name.'_gtin'));
		$this->currency = Tools::getValue('currency', Configuration::get($this->name.'_currency'));
		$this->id_lang = Tools::getValue('language', Configuration::get($this->name.'_lang'));
	  
		$this->_html .= '<form action="'.$_SERVER['REQUEST_URI'].'" method="post">
				<center><input name="btnSubmit" id="btnSubmit" class="button" value="'.$this->l('Generate XML feed file').'" type="submit" /></center>
				</form>'.
				'<form action="'.$_SERVER['REQUEST_URI'].'" method="post">
					<br />
					<fieldset>
						<legend><img src="../img/admin/cog.gif" alt="" class="middle" />'.$this->l('Settings').'</legend>
						<fieldset class="space">
							<p style="font-size: smaller;"><img src="../img/admin/unknown.gif" alt="" class="middle" />'.
				$this->l('The following allow localisation of the feed for both language and currency, which can be selected independently.
						 Remember to change the <strong>output location</strong> below if you want to generate and retain multiple feed files with different
						 language and currency combinations. Note that after updating these settings a new recommended Output Location will be suggested below
						 but <em>will not automatically be used</em>.').'</p>
						</fieldset>
						<br />
			  <label>'.$this->l('Currency').'</label>
			  <div class="margin-form">
				<select name="currency" id="currency" >';
				foreach ($this->currencies as $id => $currency)
					if ($id)
						$this->_html .= '<option value="'.$id.'"'.($this->currency == $id ? ' selected="selected"' : '').' > '.$currency->iso_code.' </option>';
				
				$this->_html .= '</select>
				<p class="clear">'.$this->l('Store default =').' '.$this->currencies[(int)Configuration::get('PS_CURRENCY_DEFAULT')]->iso_code.'</p>
			  </div>
			  <label>'.$this->l('Language').'</label>
			  <div class="margin-form">
				<select name="language" id="language" >';
				foreach ($this->languages as $language)
					$this->_html .= '<option value="'.$language['id_lang'].'"'.($this->id_lang == $language['id_lang'] ? ' selected="selected"' : '').' > '.$language['name'].' </option>';
				
				$this->_html .= '</select>
				<p class="clear">'.$this->l('Store default =').' '.$this->languages[$this->context->cookie->id_lang]['name'].'</p>
			  </div>
				<label>'.$this->l('Target Country').'</label>
				<div class="margin-form">
					<select name="country" id="country" >
					<option value="Australia"'.($this->country == 'Australia' ? ' selected="selected"' : '').' >Australia</option>
					<option value="Brazil"'.($this->country == 'Brazil' ? ' selected="selected"' : '').' >Brazil</option>
					<option value="Switzerland"'.($this->country == 'Switzerland' ? ' selected="selected"' : '').' >Switzerland</option>
					<option value="China"'.($this->country == 'China' ? ' selected="selected"' : '').' >China</option>
					<option value="Germany"'.($this->country == 'Germany' ? ' selected="selected"' : '').' >Germany</option>
					<option value="Spain"'.($this->country == 'Spain' ? ' selected="selected"' : '').' >Spain</option>
					<option value="France"'.($this->country == 'France' ? ' selected="selected"' : '').' >France</option>
					<option value="United Kingdom"'.($this->country == 'United Kingdom' ? ' selected="selected"' : '').' >United Kingdom</option>
					<option value="Italy"'.($this->country == 'Italy' ? ' selected="selected"' : '').' >Italy</option>
					<option value="Japan"'.($this->country == 'Japan' ? ' selected="selected"' : '').' >Japan</option>
					<option value="Netherlands"'.($this->country == 'Netherlands' ? ' selected="selected"' : '').' >Netherlands</option>
					<option value="United States"'.($this->country == 'United States' ? ' selected="selected"' : '').' >United States</option>
					</select>
					<p class="clear">'.$this->l('For country-specific rules. Note that the language and currency settings should match.').'</p>
				</div>
				<label>'.$this->l('Default Tax: ').'</label>
				<div class="margin-form">
					<input name="default_tax" type="text" value="'.Tools::getValue('default_tax', Configuration::get($this->name.'_default_tax')).'"/>
					<p class="clear">'.$this->l('<strong>US Only</strong>. Set this value to the percentage rate you defined in Merchant Center. Any tax group matching this rate will be omitted (reduces xml file size).').'</p>
				</div>
				<label>'.$this->l('Ignore Tax: ').'</label>
				<div class="margin-form">
					<input type="checkbox" name="ignore_tax" id="ignore_tax" value="1"'.($this->ignore_tax ? 'checked="checked" ' : '').' />
					<p class="clear">'.$this->l('<strong>US Only</strong>. When checked no tax group information will be generated.').'</p>
				</div>
				<label>'.$this->l('Ignore Shipping: ').'</label>
				<div class="margin-form">
					<input type="checkbox" name="ignore_shipping" id="ignore_shipping" value="1"'.($this->ignore_shipping ? 'checked="checked" ' : '').' />
					<p class="clear">'.$this->l('When checked no shipping information will be generated.').'</p>
				</div>
			  <fieldset class="space">
							<p style="font-size: smaller;"><img src="../img/admin/unknown.gif" alt="" class="middle" />'.
				$this->l('The minimum <em>required</em> configuration is to define a description for your feed. This should be text (not html),
						 up to a maximum length of 10,000 characters. Ideally, greater than 15 characters and 3 words. It is suggested that this should be written
						 in the language selected above.').'</p>
						</fieldset>
						<br />
						<label>'.$this->l('Feed Description: ').'</label>
						<div class="margin-form">
							<textarea name="description" rows="5" cols="80" >'.Tools::getValue('description', Configuration::get($this->name.'_description')).'</textarea>
							<p class="clear">'.$this->l('Example:').' Our range of fabulous products</p>
						</div>
						<label>'.$this->l('Output Location: ').'</label>
						<div class="margin-form">
							<input name="filepath" type="text" style="width: 600px;" value="'.(isset($_POST['filepath']) ? $_POST['filepath'] : $this->_winFixFilename(Configuration::get($this->name.'_filepath'))).'"/>
							<p class="clear">'.$this->l('Recommended path:').' '.$this->_defaultOutputFile().'</p>
						</div>
			  <fieldset class="space">
							<p style="font-size: smaller;"><img src="../img/admin/unknown.gif" alt="" class="middle" />'.
				$this->l('Google have certain mandatory requirements for accepting feedfiles which vary between countries. As a minimum you should
						 have valid entries in your product catalog for one or both of the following in addition to properly entering the manufacturer per product. Your
						 product <strong>will be rejected</strong> if you do not have valid data for The "Unique Product Identifier" setting chosen below <strong>plus</strong>
						 either a valid manufacturer assigned to your products <strong>AND/OR</strong> the "Supplier Reference" enabled and populated for your products.').'</p>
						</fieldset>
						<br />
			  <label>'.$this->l('Use Supplier Reference').'</label>
			  <div class="margin-form">
				<input type="checkbox" name="use_supplier" id="use_supplier" value="on"'.($this->use_supplier == 'on' ? 'checked="checked" ' : '').' />
				<p class="clear">'.$this->l('Use the supplier reference field (default) rather than the reference field as Manufacturers Part Number (MPN)').'</p>
			  </div>
				
			  <label>'.$this->l('Global Trade Item Numbers').'</label>
				<div class="margin-form">
					<input type="radio" name="gtin" id="gtin_0" value="ean13" '.($this->gtin_field == 'ean13' ? 'checked="checked" ' : '').' > EAN13</option>
					<input type="radio" name="gtin" id="gtin_1" value="upc" '.($this->gtin_field == 'upc' ? 'checked="checked" ' : '').' > UPC</option>
					<input type="radio" name="gtin" id="gtin_2" value="isbn10" '.($this->gtin_field == 'isbn10' ? 'checked="checked" ' : '').' > ISBN-10</option>
					<input type="radio" name="gtin" id="gtin_3" value="isbn13" '.($this->gtin_field == 'isbn13' ? 'checked="checked" ' : '').' > ISBN-13</option>
					<input type="radio" name="gtin" id="gtin_4" value="jan8" '.($this->gtin_field == 'jan8' ? 'checked="checked" ' : '').' > JAN (8-digit)</option>
					<input type="radio" name="gtin" id="gtin_5" value="jan13" '.($this->gtin_field == 'jan13' ? 'checked="checked" ' : '').' > JAN (13-digit)</option>
					<input type="radio" name="gtin" id="gtin_6" value="none" '.($this->gtin_field == 'none' ? 'checked="checked" ' : '').' > None</option>
					<p class="clear">'.$this->l('Choose the identifier most suitable for your region and/or products. These identifiers are UPC (in North America),
																			EAN (in Europe), JAN (in Japan) and ISBN (for books). JAN and ISBN numbers should be entered in the
																			Prestashop EAN field. You can include any of these values within this attribute:').'</p>
					<ul>
					<li>'.$this->l('UPC: 12-digit number such as 001234567891').'</li>
					<li>'.$this->l('EAN: 13-digit number such as 1001234567891').'</li>
					<li>'.$this->l('JAN: 8 or 13-digit number such as 12345678 or 1234567890123').'</li>
					<li>'.$this->l('ISBN: 10 or 13-digit number such as 0451524233. If you have both, only include 13-digit number.').'</li>
					</ul>
					<p class="clear">'.$this->l('Required for all items - except for clothing and custom made goods, or if you\'re providing \'brand\' and \'mpn\'.').'</p>
				</div>
				<p style="font-size: smaller;"><img src="../img/admin/unknown.gif" alt="" class="middle" />'.
					$this->l('<strong>Remember to click below to save any changes made before running the feed.</strong>').
				'</p>
			  
				<input name="btnUpdate" id="btnUpdate" class="button" value="'.((!file_exists($this->_winFixFilename(Configuration::get($this->name.'_filepath')))) ? $this->l('Update Settings') : $this->l('Update Settings')).'" type="submit" />
					</fieldset>';

				if (Tools::usingSecureMode())
					$domain = Tools::getShopDomainSsl(true);
				else
					$domain = Tools::getShopDomain(true);
				$this->_html .= '<fieldset class="space">
				<legend><img src="../img/admin/cog.gif" alt="" class="middle" />'.$this->l('Cron Job').'</legend>
				<p>
					<b>'.$domain.__PS_BASE_URI__.'modules/googlebase/googlebase-cron.php?token='.substr(Tools::encrypt('googlebase/cron'), 0, 10).'&module=googlebase</b>
				</p>
				</fieldset>';
				
				$this->_html .= '</form><br/>';
	}
	
	/**
	* Configuration form field validation
	*
	* Errors and warnings are stored in the class data members
	*/
	private function _postValidation()
	{
		// TODO Need to review form validation.....
		// Used $_POST here to allow us to modify them directly - naughty I know :)
		
		Configuration::updateValue($this->name.'_use_supplier', Tools::getValue('use_supplier') ? Tools::getValue('use_supplier') : 'off');
  
		if (empty($_POST['description']) || strlen($_POST['description']) > 10000)
			$this->_mod_errors[] = $this->l('Description is invalid');
		// could check that this is a valid path, but the next test will
		// do that for us anyway
		// But first we need to get rid of the escape characters
		$_POST['filepath'] = $this->_winFixFilename($_POST['filepath']);
		if (empty($_POST['filepath']) || (strlen($_POST['filepath']) > 255))
			$this->_mod_errors[] = $this->l('The target location is invalid');
  
		if (file_exists($_POST['filepath']) && !is_writable($_POST['filepath']))
			$this->_mod_errors[] = $this->l('File error.<br />Cannot write to').' '.$_POST['filepath'];
	}
	
	/**
	* Standard configuration screen display function
	*
	* @return string	The HTML to display in the Admin screen
	*/
	public function getContent()
	{
		$this->_html .= '<h2>'.$this->l('[BETA]Google Base Products Feed').' {PS v'._PS_VERSION_.'}</h2>';
		if (!is_writable($this->_directory()))
			$this->_mod_warnings[] = $this->l('Output directory must be writable or the feed file will need to be pre-created with write permissions.');
  
		if (isset($this->_mod_warnings) && count($this->_mod_warnings))
		  $this->_displayWarnings($this->_mod_warnings);
  
		if (Tools::getValue('btnUpdate'))
		{
			$this->_postValidation();
  
			if (!count($this->_mod_errors))
			{
				Configuration::updateValue($this->name.'_description', Tools::getValue('description'));
				Configuration::updateValue($this->name.'_filepath', addslashes($_POST['filepath'])); // the Tools class kills the windows file name separators :(
				Configuration::updateValue($this->name.'_gtin', Tools::getValue('gtin'));	// gtin field selection
				Configuration::updateValue($this->name.'_use_supplier', Tools::getValue('use_supplier') ? Tools::getValue('use_supplier') : 'off');
				Configuration::updateValue($this->name.'_currency', (int)Tools::getValue('currency')); // Feed currency
				Configuration::updateValue($this->name.'_lang', (int)Tools::getValue('language'));	// language to generate feed for
				Configuration::updateValue($this->name.'_country', Tools::getValue('country'));
        // A little fix just in case the % sign gets added....
        Configuration::updateValue($this->name.'_default_tax', str_replace('%', '', Tools::getValue('default_tax')));
        Configuration::updateValue($this->name.'_ignore_tax', (int)(Tools::isSubmit('ignore_tax')));
        Configuration::updateValue($this->name.'_ignore_shipping', (int)(Tools::isSubmit('ignore_shipping')));
				$this->_getGlobals();
			} elseif (isset($this->_mod_errors) && count($this->_mod_errors))
				$this->_displayErrors($this->_mod_errors);
		} else if (Tools::getValue('btnSubmit'))
			$this->_postProcess();
  
		$this->_displayForm();
		$this->_displayFeed();
  
		return $this->_html;
	}
	
	/**
	* Display any module warnings
	*
	* @param	array		The generated warnings to display
	* @return		string	HTML formatted warnings	
	*/
	private function _displayWarnings($warn)
	{
		$str_output = '';
		if (!empty($warn))
		{
			$str_output .= '<script type="text/javascript">
					$(document).ready(function() {
						$(\'#linkSeeMore\').unbind(\'click\').click(function(){
							$(\'#seeMore\').show(\'slow\');
							$(this).hide();
							$(\'#linkHide\').show();
							return false;
						});
						$(\'#linkHide\').unbind(\'click\').click(function(){
							$(\'#seeMore\').hide(\'slow\');
							$(this).hide();
							$(\'#linkSeeMore\').show();
							return false;
						});
						$(\'#hideWarn\').unbind(\'click\').click(function(){
							$(\'.warn\').hide(\'slow\', function (){
								$(\'.warn\').remove();
							});
							return false;
						});
					});
				  </script>
			<div class="warn">';
			if (!is_array($warn))
				$str_output .= '<img src="../img/admin/warn2.png" />'.$warn;
			else
			{	$str_output .= '<span style="float:right"><a id="hideWarn" href=""><img alt="X" src="../img/admin/close.png" /></a></span><img src="../img/admin/warn2.png" />'.
				(count($warn) > 1 ? $this->l('There are') : $this->l('There is')).' '.count($warn).' '.(count($warn) > 1 ? $this->l('warnings') : $this->l('warning'))
				.'<span style="margin-left:20px;" id="labelSeeMore">
				<a id="linkSeeMore" href="#" style="text-decoration:underline">'.$this->l('Click here to see more').'</a>
				<a id="linkHide" href="#" style="text-decoration:underline;display:none">'.$this->l('Hide warning').'</a></span><ul style="display:none;" id="seeMore">';
				foreach ($warn as $val)
					$str_output .= '<li>'.$val.'</li>';
				$str_output .= '</ul>';
			}
			$str_output .= '</div>';
		}
		echo $str_output;
	}
	
	/**
	* Display any module errors
	*
	* @param	array	The generated errors to display
	* * @return		string	HTML formatted errors	
	*/
	private function _displayErrors()
	{
		if ($nbErrors = count($this->_mod_errors))
		{
			echo '<script type="text/javascript">
				$(document).ready(function() {
					$(\'#hideError\').unbind(\'click\').click(function(){
						$(\'.error\').hide(\'slow\', function (){
							$(\'.error\').remove();
						});
						return false;
					});
				});
			  </script>
			<div class="error"><span style="float:right"><a id="hideError" href=""><img alt="X" src="../img/admin/close.png" /></a></span><img src="../img/admin/error2.png" />';
			if (count($this->_mod_errors) == 1)
				echo $this->_mod_errors[0];
			else
			{
				echo $nbErrors.' '.$this->l('errors').'<br /><ol>';
				foreach ($this->_mod_errors as $error)
					echo '<li>'.$error.'</li>';
				echo '</ol>';
			}
			echo '</div>';
		}
	}
	
	/*
	 * General support and low-level utilities
	 */
	
	/**
	* Convert entities 
	*
	* @param	string	$string The string to process
	* @return		string						The processed string
	*/	
	private function _xmlentities($string)
	{
		$string = str_replace('&', '&amp;', $string);
		$string = str_replace('"', '&quot;', $string);
		$string = str_replace('\'', '&apos;', $string);
		$string = str_replace('`', '&apos;', $string);
		$string = str_replace('<', '&lt;', $string);
		$string = str_replace('>', '&gt;', $string);
		 return ($string);
	}

	/**
	* Format name:value into an XML element format
	*
	* @param	string	$name The element name
	* @param	string	$value  The value for the element
	* @param	bool 		$encoding Whether to encode entities
	* @param 	bool 		$force_zero Decide whether "zero" is a valid value for this element (otherwise omit whole element)
	* @param 	bool			$integer Treat value as an integer for special handling
	* @return		string							The formatted XML element
	*/	
	private function _xmlElement($name, $value, $encoding = false, $force_zero = false, $integer = false)
	{
		$element = '';
    if ((!empty($value) && !($integer && (int)$value == 0)) || $force_zero)
		{
			if ($encoding)
				$value = $this->_xmlentities($value);
			$element .= '<'.$name.'>'.$value.'</'.$name.'>'."\n";
		}
		return $element;
	}
	
	/**
	* Check product quantity available
	*
	* @param	array		$product	The product data retrieved from the database
	* @return		bool										The result of the test
	*/
	private function _checkQty($product, $id_product_attribute = 0, $qty = 1)
	{
		if (Pack::isPack((int)$product['id_product']) && !Pack::isInStock((int)$product['id_product']))
			return false;

		if (Product::isAvailableWhenOutOfStock(StockAvailable::outOfStock($product['id_product'])))
			return true;

		return false;
	}

	/**
	* Recursive function to generate category path 
	*
	* @param	int	 $id_category The category id currently being processed
	* @param string $path The current category path
	* @return string					The resulting full category path
	*/
	private function _getPath($id_category, $path = '')
	{
		$category = new Category(intval($id_category), intval(Configuration::get($this->name.'_lang')));
	
		if (!Validate::isLoadedObject($category))
		{
			$this->_mod_errors[] = $this->l('Error processing category with id= ').$id_category.' product_id = '.$this->current_product;
			return '';
		}
  
		if ($category->id == 1)
			return htmlentities($path);
  
		$pipe = ' > ';
  
		// Fix for legacy ordering via numeric prefix
		$category_name = preg_replace('/^[0-9]+\./', '', $category->name);
  
		if ($path != $category_name)
			$path = $category_name.($path != '' ? $pipe.$path : '');
  
		return $this->_getPath(intval($category->id_parent), $path);
	}

}
