<?php

if (!defined('_PS_VERSION_'))
	exit;
	
class GoogleBase extends Module
{
	private $_html = '';
	private $_postErrors = array();
	private $_cookie;
	private $_compat;
	private $_warnings;
	private $_mod_errors;
	
	private $xml_description;
	private $psdir;
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
	private $nearby;

	public function __construct()
	{
		$version_mask = explode('.', _PS_VERSION_, 3);
		
		$this->_compat = (int)($version_mask[0]*10)+$version_mask[1];
		$this->_warnings = array();
		$this->_mod_errors = array();
		
		$this->name = 'googlebase';
		$this->tab = $this->_compat > 13 ? 'advertising_marketing' : 'Tools';
		if ($this->_compat > 13)
			$this->author = 'eCartService.net';
		$this->version = '0.8';
		$this->need_instance = 0;
		
		parent::__construct();
		
		if ($this->_compat < 15) {
			global $cookie;
			$this->_cookie = $cookie;
		} else {
			$this->_cookie = $this->context->cookie;
		}
		
		// Set default config values if they don't already exist (here for compatibility in case the user doesn't uninstall/install at upgrade)
		// Also set global "macro" data for the feed and check for store configuration changes
		if ($this->isInstalled($this->name))
		{
			// deprecated
			if (Configuration::get($this->name.'_domain'))
				Configuration::deleteByName($this->name.'_domain');
			// deprecated
			if (Configuration::get($this->name.'_psdir'))
				Configuration::deleteByName($this->name.'_psdir');
			
			$this->_setDefaults();
		}

		$this->displayName = $this->l('[BETA]Google Base Feed Products');
		$this->description = $this->l('Generate your products feed for Google Products Search. www.ecartservice.net');
	}

	public function install()
	{
		$this->_setDefaults();
		return parent::install();
	}

	private function _setDefaults()
	{
		if (!Configuration::get($this->name.'_description'))
			Configuration::updateValue($this->name.'_description', '****Type some text to describe your shop before generating your first feed****');
		if (!Configuration::get($this->name.'_lang'))
			Configuration::updateValue($this->name.'_lang', $this->_cookie->id_lang);
		if (!Configuration::get($this->name.'_gtin'))
			Configuration::updateValue($this->name.'_gtin', 'ean13');
		if (!Configuration::get($this->name.'_use_supplier'))
			Configuration::updateValue($this->name.'_use_supplier', 1);
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
			Configuration::updateValue($this->name.'_filepath', addslashes($this->defaultOutputFile()));
	
		$this->_nearby = false;
	}

	private function _getGlobals()
	{
		$this->xml_description = Configuration::get($this->name.'_description');
		$this->psdir = __PS_BASE_URI__;
	  
		$this->languages = $this->getLanguages();
		$this->id_lang = intval(Configuration::get($this->name.'_lang'));
		$this->lang_iso = strtolower(Language::getIsoById($this->id_lang));
		if (!isset($this->languages[$this->id_lang]))
		{
			Configuration::updateValue($this->name.'_lang', (int)$this->_cookie->id_lang);
			$this->id_lang = (int)$this->_cookie->id_lang;
			$this->lang_iso = strtolower(Language::getIsoById($this->id_lang));
			$this->warnings[] = $this->l('Language configuration is invalid - reset to default.');
		}
	  
		$this->gtin_field = Configuration::get($this->name.'_gtin');
	  
		$this->use_supplier = Configuration::get($this->name.'_use_supplier');
	  
		$this->currencies = $this->getCurrencies();
		$this->id_currency = intval(Configuration::get($this->name.'_currency'));
		if (!isset($this->currencies[$this->id_currency]))
		{
			Configuration::updateValue($this->name.'_currency', (int)Configuration::get('PS_CURRENCY_DEFAULT'));
			$this->id_currency = (int)Configuration::get('PS_CURRENCY_DEFAULT');
			$this->warnings[] = $this->l('Currency configuration is invalid - reset to default.');
		}
	  
		$this->default_condition = Configuration::get($this->name.'_condition');

    	$this->country = Configuration::get($this->name.'_country');
    	$id_country = Country::getIdByName((int)$this->id_lang, $this->country);
    	if (!$id_country)
      		die (Tools::displayError('Failed to find target country: '.$this->country));
    	$this->target_country = new Country($id_country);
    	if (!Validate::isLoadedObject($this->target_country))
			die (Tools::displayError('Can\'t instantiate target country object '. $this->target_country));
    	$this->default_tax = (float)Configuration::get($this->name.'_default_tax');
    	$this->ignore_tax = Configuration::get($this->name.'_ignore_tax');
    	$this->ignore_shipping = Configuration::get($this->name.'_ignore_shipping');
	}

	private function directory()
	{
		return dirname(__FILE__).'/../../'; // move up to the __PS_BASE_URI__ directory
	}


	private function winFixFilename($file)
	{
		return str_replace('\\\\','\\',$file);
	}

	private function defaultOutputFile()
	{
		// PHP on windows seems to return a trailing '\' where as on unix it doesn't
		$output_dir = realpath($this->directory());
		$dir_separator = '/';

		// If there's a windows directory separator on the end,
		// then don't add the unix one too when building the final output file
		if (substr($output_dir, -1, 1)=='\\')
			$dir_separator = '';

		$output_file = $output_dir.$dir_separator.$this->lang_iso.'_'.strtolower($this->currencies[$this->id_currency]->iso_code).'_googlebase.xml';
		return $output_file;
	}


	static private $cacheCat = array();
	private function _getrawCatRewrite($id_cat)
	{
		if (!isset(self::$cacheCat[$id_cat])) {
			$row = Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow("
					SELECT `link_rewrite`
					FROM `"._DB_PREFIX_."category_lang`
					WHERE `id_category` = '".(int)($id_cat)."' AND
					`id_lang` = '".(int)$this->id_lang."'");
		
			if ($row)
			{
				self::$cacheCat[$id_cat] = $row['link_rewrite'];
				return self::$cacheCat[$id_cat];
			}
			else
			{
				self::$cacheCat[$id_cat] = '';
				$this->errors[] = $this->l('Error processing category with id='.$id_cat);
			}
		}
		return self::$cacheCat[$id_cat];
	}
	
	static private $cacheCatPath = array();
	private function getPath($id_cat)
	{
		if (!isset(self::$cacheCatPath[$id_cat]))
			self::$cacheCatPath[$id_cat] = $this->_getPath($id_cat);
	  
		return self::$cacheCatPath[$id_cat];
	}
	
	private function _getPath($id_category, $path = '')
	{
		$category = new Category(intval($id_category), intval(Configuration::get($this->name.'_lang')));
	
		if (!Validate::isLoadedObject($category))
			die (Tools::displayError('Failed to load category id= '.$id_category));
  
		if ($category->id == 1)
			return $this->_xmlentities($path);
  
		$pipe = ' > ';
  
		if ($this->_compat < 15) {
			$category_name = Category::hideCategoryPosition($category->name);
		} else {
			$category_name = preg_replace('/^[0-9]+\./', '', $category->name);
		}
  
		if ($path != $category_name)
			$path = $category_name.($path!='' ? $pipe.$path : '');
  
		return $this->_getPath(intval($category->id_parent), $path);
	}
	
	private function file_url()
	{
		$filename = $this->winFixFilename(Configuration::get($this->name.'_filepath'));
		$root_path = realpath($this->directory());
		$file = str_replace($root_path,'', $filename);
  
		$separator = '';
  
		if (substr($file, 0, 1)=='\\')
			substr_replace($file, '/', 0, 1);
  
		if (substr($file, 0, 1)!='/')
			$separator = '/';
  
		return 'http://'.$_SERVER['HTTP_HOST'].$separator.$file;
	}
	
	private function _addToFeed($str)
	{
		$filename = $this->winFixFilename(Configuration::get($this->name.'_filepath'));
		if(file_exists($filename))
		{
			$fp = fopen($filename, 'ab');
			fwrite($fp, $str, strlen($str));
			fclose($fp);
		}
	}
	
	private function _postProcess()
	{
		$products = Product::getProducts($this->id_lang, 0, NULL, 'id_product', 'ASC');
  
		if ($products) {
			if (!$fp = fopen($this->winFixFilename(Configuration::get($this->name.'_filepath')), 'w')) {
				$this->_mod_errors[] = $this->l('Error writing to feed file.');
				return;
			}
			fclose($fp);
  
			// Required headers
			$items = "<?xml version=\"1.0\"?>\n\n"
				  . "<rss version =\"2.0\" xmlns:g=\"http://base.google.com/ns/1.0\">\n\n"
				  . "<channel>\n"
				  . "<title>Google Base feed for ".$_SERVER['HTTP_HOST']."</title>\n"
				. "<link>http://".$_SERVER['HTTP_HOST']."/</link>\n"
				  . "<description>".$this->_xmlentities($this->xml_description)."</description>\n"
				  . "\n";
		
			foreach ($products AS $product) {
			 	if ($product['active']) {
					$items .= "<item>\n";
					$items .= $this->_processProduct($product);
					$items .= "</item>\n\n";
				}
			}
			$this->_addToFeed( "$items</channel>\n</rss>\n" );
		}
  
		$res = file_exists($this->winFixFilename(Configuration::get($this->name.'_filepath')));
		if ($res)
			$this->_html .= '<h3 class="conf confirm" style="margin-bottom: 20px">'.$this->l('Feed file successfully generated').'</h3>';
		else
			$this->_mod_errors[] = $this->l('Error while creating feed file');
	}
	
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
	
  	private function _xmlElement($name, $value, $encoding = false, $force_zero = false, $integer = false)
	{
		$element = '';
    if ((!empty($value) && !($integer && (int)$value==0)) || $force_zero) {
			if ($encoding)
				$value = $this->_xmlentities($value);
			$element .= "<".$name.">".$value."</".$name.">\n";
		}
		return $element;
	}
	
	private function _processProduct($product)
	{
		$item_data = '';
		$product_link = $this->_getCompatibleProductLink($product);
		$image_links = $this->_getCompatibleImageLinks($product);
		
		// Reference page: http://www.google.com/support/merchants/bin/answer.py?answer=188494
		
		// 1. Basic Product Information
		
		$item_data .= $this->_xmlElement('g:id',"pc".$this->lang_iso."-".$product['id_product']);
		$item_data .= $this->_xmlElement('title',$product['name'], true);
		
		// Try our best to get a decent description
		$description = trim(strip_tags(strlen($product['description_short']) ? $product['description_short'] :  $product['description'] ));
		// Remove invalid characters that may have been inserted incorrectly
		$description = preg_replace('/[^\x0A\x0D\x20-\x7F]/',"", $description);
		$item_data .= $this->_xmlElement('description','<![CDATA['.$description.']]>');
		// google product category <g:google_product_category /> - Google's category of the item (TODO: support this!)
		
		
		$item_data .= $this->_xmlElement('g:product_type',$this->getPath($product['id_category_default']));
		$item_data .= $this->_xmlElement('link',$product_link, true);
		if ($image_links[0]['valid'] == 1)
			$item_data .= $this->_xmlElement('g:image_link',$image_links[0]['link'], true);
		if ($image_links[1]['valid'] == 1)
			$item_data .= $this->_xmlElement('g:additional_image_link',$image_links[1]['link'], true);
		
		if ((int)$this->_compat > 13)
			$item_data .= $this->_xmlElement('g:condition', $this->_getCompatibleCondition($product['condition']));
		else
			$item_data .= $this->_xmlElement('g:condition',$this->_getCompatibleCondition($this->default_condition));
		
		// 2. Availability & Price
		$item_data .= $this->_xmlElement('g:availability',$this->getAvailability($product));
		// Price is WITHOUT any reduction
		$price = $this->_getCompatiblePrice($product['id_product']);
		$item_data .= $this->_xmlElement('g:price', $price);
		// TODO: If there is an active discount, then include it
		$price_with_reduction = $this->_getCompatibleSalePrice($product['id_product']);
		if ($price_with_reduction !== $price)
			$item_data .= $this->_xmlElement('g:sale_price',$price_with_reduction);
		/*
		// Effective date is in ISO8601 format TODO: Support "sales" somehow - need a way of returning "expiry date" for the reduction
		$items .= "<g:sale_price_effective_date>".Product::getPriceStatic(intval($product['id_product']))."</g:sale_price_effective_date>\n";
		*/
		
		// 3. Unique Product Identifiers
		// brand
		$item_data .= $this->_xmlElement('g:brand',$product['manufacturer_name'], true);
		// gtin values
		$item_data .= $this->_xmlElement('g:gtin', $this->_getGtinValue($product), false, false, true);
		// manufacturer part # (supplier ref)
		$item_data .= $this->_xmlElement('g:mpn','<![CDATA['.$this->_getCompatibleSupplierRef($product).']]>');
		// 6. Tax & Shipping
		if ($this->country == 'United States' && $this->_compat > 13 && !$this->ignore_tax)
			$item_data .= $this->_xmlTaxGroups($product);

		if (!$this->ignore_shipping && $this->_compat > 13)
			$item_data .= $this->_xmlShippingGroups($product);

   	$item_data .= $this->_xmlElement('g:shipping_weight',$product['weight'] ? number_format($product['weight'],2, '.', '').' '.Configuration::get('PS_WEIGHT_UNIT') : 0);

		// 7. Nearby Stores (US & UK only)
    if (($this->country == 'United States' || $this->country == 'United Kingdom') && $this->_compat > 13)
			$item_data .= $this->_xmlElement('g:online_only',$product['online_only'] == 1 ? 'y' : 'n');
		
		return $item_data;
	}
  
	private function _getGtinValue($product)
	{
		$gtin = '';
	
		switch ($this->gtin_field) {
			case 'ean13':
				$gtin = sprintf('%1$013d',$product['ean13']);
			break;
			case 'upc':
				$gtin = sprintf('%1$012d',$product['upc']);
			break;
			case 'isbn10':
				$gtin = sprintf('%1$010d',$product['ean13']);
			break;
			case 'isbn13':
				$gtin = sprintf('%1$013d',$product['ean13']);
			break;
			case 'jan8':
				$gtin = sprintf('%1$08d',$product['ean13']);
			break;
			case 'jan13':
				$gtin = sprintf('%1$013d',$product['ean13']);
			break;
			case 'none':
				$gtin = '';
			break;
		}
		return $gtin;
	}

	private function _getCompatibleCondition($condition)
	{
		switch ($condition) {
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
	
  private function _getCompatiblePrice($id_product, $id_product_attrib = NULL, $force_tax = false)
	{
    if ($this->country == 'United States' && !$force_tax)
      $use_tax = false;
    else
      $use_tax = true;

    $price = number_format(Tools::convertPrice(Product::getPriceStatic(intval($id_product), $use_tax, $id_product_attrib, 6, NULL, false, false), $this->currencies[$this->id_currency]), 2, '.', '');
		
		return $price.' '.$this->currencies[$this->id_currency]->iso_code;
	}
	
  private function _getCompatibleSalePrice($id_product, $id_product_attrib = NULL, $force_tax = false)
	{
    if ($this->country == 'United States' && !$force_tax)
      $use_tax = false;
    else
      $use_tax = true;

    $price = number_format(Tools::convertPrice(Product::getPriceStatic(intval($id_product), $use_tax, $id_product_attrib, 6), $this->currencies[$this->id_currency]), 2, '.', '');
		
		return $price.' '.$this->currencies[$this->id_currency]->iso_code;
	}
	
	private function _getCompatibleImageLinks($product)
	{
		if ($this->_compat > 14) {
			$link = $this->context->link;
		} else {
		$link = new Link();
		}
		$image_data = array(array('link' => '', 'valid' => 0), array('link' => '', 'valid' => 0));
		$images = Image::getImages($this->id_lang, $product['id_product']);
		
		switch ($this->_compat) {
			case '11':
				if (isset($images[0])) {
					$image_data[0]['link'] = 'http://'.$_SERVER['HTTP_HOST'].$this->psdir.'img/p/'.$images[0]['id_product'].'-'.$images[0]['id_image'].'-large.jpg';
					$image_data[0]['valid'] = 1;
				}
				if (isset($images[1])) {
					$image_data[1]['link'] = 'http://'.$_SERVER['HTTP_HOST'].$this->psdir.'img/p/'.$images[1]['id_product'].'-'.$images[1]['id_image'].'-large.jpg';
					$image_data[1]['valid'] = 1;
				}
			break;
		  
			case '15':
			case '14':
				if (isset($images[0])) {
					$image_data[0]['link'] = $link->getImageLink($product['link_rewrite'], (int)$product['id_product'].'-'.(int)$images[0]['id_image']);
					$image_data[0]['valid'] = 1;
				}
				if (isset($images[1])) {
					$image_data[1]['link'] = $link->getImageLink($product['link_rewrite'], (int)$product['id_product'].'-'.(int)$images[1]['id_image']);
					$image_data[1]['valid'] = 1;
				}
			break;
		  
			default:
				if (isset($images[0])) {
					$image_data[0]['link'] = 'http://'.$_SERVER['HTTP_HOST'].$this->psdir.$link->getImageLink($product['link_rewrite'], (int)$product['id_product'].'-'.(int)$images[0]['id_image']);
					$image_data[0]['valid'] = 1;
				}
				if (isset($images[1])) {
					$image_data[1]['link'] = 'http://'.$_SERVER['HTTP_HOST'].$this->psdir.$link->getImageLink($product['link_rewrite'], (int)$product['id_product'].'-'.(int)$images[1]['id_image']);;
					$image_data[1]['valid'] = 1;
				}
			break;
		
		}
		return $image_data;
	}
	
	private function _getCompatibleProductLink($product)
	{
		if ($this->_compat > 14) {
			$link = $this->context->link;
		} else {
		$link = new Link();
		}
		switch ($this->_compat) {
			case '11':
				$product_link = $link->getProductLink($product['id_product'], $product['link_rewrite']);
				// Make 1.1 result look like 1.2+
				if (strpos( $product_link, 'http://' ) === false )
					$product_link = 'http://'.$_SERVER['HTTP_HOST'].$product_link;
			break;
		  
			case '12':
				$product_link = $link->getProductLink((int)($product['id_product']), $product['link_rewrite'], $this->_getrawCatRewrite($product['id_category_default']), $product['ean13']);
			break;
		
			case '13':
				$product_link = $link->getProductLink((int)($product['id_product']), $product['link_rewrite'], $this->_getrawCatRewrite($product['id_category_default']), $product['ean13'], (int)$this->id_lang);
			break;
		
			default:
				$product_link = $link->getProductLink($product, null, null, null, (int)$this->id_lang);
			break;
		}
		
		return $product_link;
	}
	
	private function _getCompatibleSupplierRef($product) {
		if ($this->_compat < 15) {
			if ($this->use_supplier && !empty($product['supplier_reference'])) {
				return $product['supplier_reference'];
			}
		} else {
			if (isset($product['id_supplier']) && !empty($product['id_supplier'])) {
				return ProductSupplier::getProductSupplierReference($product['id_product'], 0, $product['id_supplier']);
			}
		}	
	}
	
	private function _displayFeed()
	{
		$filename = $this->winFixFilename(Configuration::get($this->name.'_filepath'));
		if(file_exists($filename)) {
			$this->_html .= '<fieldset><legend><img src="../img/admin/enabled.gif" alt="" class="middle" />'.$this->l('Feed Generated').'</legend>';
			if (strpos($filename,realpath($this->directory())) === FALSE)
			{
				$this->_html .= '<p>'.$this->l('Your Google Base feed file is available via ftp as the following:').' <b>'.$filename.'</b></p><br />';
			} else {
				$this->_html .= '<p>'.$this->l('Your Google Base feed file is online at the following address:').' <a href="'.$this->file_url().'"><b>'.$this->file_url().'</b></a></p><br />';
			}
			$this->_html .= $this->l('Last Updated:').' <b>'.date('m.d.y G:i:s', filemtime($filename)).'</b><br />';
			$this->_html .= '</fieldset>';
		} else {
			$this->_html .= '<fieldset><legend><img src="../img/admin/delete.gif" alt="" class="middle" />'.$this->l('No Feed Generated').'</legend>';
			$this->_html .= '<br /><h3 class="alert error" style="margin-bottom: 20px">No feed file has been generated at this location yet!</h3>';
			$this->_html .= '</fieldset>';
		}
	}
	
	private function _displayForm()
	{
		$this->use_supplier = (int)(Tools::isSubmit('use_supplier') ? 1 : Configuration::get($this->name.'_use_supplier'));
		$this->gtin_field = Tools::getValue('gtin', Configuration::get($this->name.'_gtin'));
		$this->currency = Tools::getValue('currency', Configuration::get($this->name.'_currency'));
		$this->id_lang = Tools::getValue('language', Configuration::get($this->name.'_lang'));
		$this->country = Tools::getValue('country', Configuration::get($this->name.'_country'));
		$this->ignore_tax = (int)(Tools::isSubmit('ignore_tax') ? 1 : Configuration::get($this->name.'_ignore_tax'));
		$this->ignore_shipping = (int)(Tools::isSubmit('ignore_shipping') ? 1 : Configuration::get($this->name.'_ignore_shipping'));
	  
		$this->_html .=
				'<form action="'.$_SERVER['REQUEST_URI'].'" method="post">
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
                but <em>will not automatically be used</em>. You should select the country you are targetting below to ensure regional differences are handled correctly.').'
							</p>
						</fieldset>
						<br />
			  <label>'.$this->l('Currency').'</label>
			  <div class="margin-form">
				<select name="currency" id="currency" >';
				foreach ($this->currencies as $id => $currency) {
					if ($id)
					$this->_html .= '<option value="'.$id.'"'.($this->currency == $id ? ' selected="selected"' : '').' > '.$currency->iso_code.' </option>';
				}
				$this->_html .='</select>
				<p class="clear">'.$this->l('Store default ='). ' ' . $this->currencies[(int)Configuration::get('PS_CURRENCY_DEFAULT')]->iso_code.'</p>
			  </div>
			  <label>'.$this->l('Language').'</label>
			  <div class="margin-form">
				<select name="language" id="language" >';
				foreach ($this->languages as $language) {
					$this->_html .= '<option value="'.$language['id_lang'].'"'.($this->id_lang == $language['id_lang'] ? ' selected="selected"' : '').' > '.$language['name'].' </option>';
				}
				$this->_html .='</select>
				<p class="clear">'.$this->l('Store default ='). ' ' . $this->languages[$this->_cookie->id_lang]['name'].'</p>
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
					<input type="checkbox" name="ignore_tax" id="ignore_tax" value="1"' . ($this->ignore_tax ? 'checked="checked" ' : '') . ' />
					<p class="clear">'.$this->l('<strong>US Only</strong>. When checked no tax group information will be generated.').'</p>
				</div>
				<label>'.$this->l('Ignore Shipping: ').'</label>
				<div class="margin-form">
					<input type="checkbox" name="ignore_shipping" id="ignore_shipping" value="1"' . ($this->ignore_shipping ? 'checked="checked" ' : '') . ' />
					<p class="clear">'.$this->l('When checked no shipping information will be generated.').'</p>
				</div>
				<fieldset class="space">
							<p style="font-size: smaller;"><img src="../img/admin/unknown.gif" alt="" class="middle" />'.
								$this->l('The minimum <em>required</em> configuration is to define a description for your feed. This should be text (not html),
								up to a maximum length of 10,000 characters. Ideally, greater than 15 characters and 3 words. It is suggested that this should be written
								in the language selected above.').'
							</p>
				</fieldset>
				<br />
				<label>'.$this->l('Feed Description: ').'</label>
				<div class="margin-form">
					<textarea name="description" rows="5" cols="80" >'.Tools::getValue('description', Configuration::get($this->name.'_description')).'</textarea>
				<p class="clear">'.$this->l('Example: Our range of fabulous products').'</p>
				</div>
				<label>'.$this->l('Output Location: ').'</label>
				<div class="margin-form">
					<input name="filepath" type="text" style="width: 600px;" value="'.(isset($_POST['filepath']) ? $_POST['filepath'] : $this->winFixFilename(Configuration::get($this->name.'_filepath'))).'"/>
					<p class="clear">'.$this->l('Recommended path:').' '.$this->defaultOutputFile().'</p>
				</div>
				<fieldset class="space">
							<p style="font-size: smaller;"><img src="../img/admin/unknown.gif" alt="" class="middle" />'.
						$this->l('Unique product identifiers such as UPC, EAN, JAN or ISBN allow Google to show your listing on the appropriate product page. If you don\'t provide
										 the required unique product identifiers, your store may not appear on product pages and all your items may be removed from Product Search.<br /><br />').
						$this->l('Google require unique product identifiers for all products - except for custom made goods. For clothing, you must submit the \'brand\' attribute.
										 For media (such as books, movies, music and video games), you must submit the \'gtin\' attribute. In all cases, they recommend that you submit
										 all three attributes.<br /><br />').
						$this->l('You need to submit at least two attributes of \'brand\', \'gtin\' and \'mpn\', but Google recommend that you submit all three if available. For media
										 (such as books, films, music and video games), you must submit the \'gtin\' attribute, but they recommend that you include \'brand\' and \'mpn\' if
										 available.').
						'</p>
						</fieldset>
						<br />
						<label>'.$this->l('Use Supplier Reference').'</label>
						<div class="margin-form">
						<input type="checkbox" name="use_supplier" id="use_supplier" value="1"' . ($this->use_supplier ? 'checked="checked" ' : '') . ' />
								<p class="clear">'.$this->l('Use the supplier reference field as Manufacturers Part Number (MPN). This code uniquely identifies the product to its
																						manufacturer. In particular, the combination of brand and MPN clearly specifies one product. Required for all items -
																						except clothing, media, and custom made goods or if you\'re providing \'brand\' and \'gtin\'.').'
								</p>
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
						<input name="btnUpdate" id="btnUpdate" class="button" value="'.$this->l('Update Settings').'" type="submit" />
				</fieldset>
			</form><br/>';
	}
	
	private function _postValidation()
	{
		// TODO Need to review form validation.....
		// Used $_POST here to allow us to modify them directly - naughty I know :)
  
		if (empty($_POST['description']) OR strlen($_POST['description']) > 10000)
			$this->_mod_errors[] = $this->l('Description is invalid');
		// could check that this is a valid path, but the next test will
		// do that for us anyway
		// But first we need to get rid of the escape characters
		$_POST['filepath'] = $this->winFixFilename($_POST['filepath']);
		if (empty($_POST['filepath']) OR (strlen($_POST['filepath']) > 255))
			$this->_mod_errors[] = $this->l('The target location is invalid');
  
		if (file_exists($_POST['filepath']) && !is_writable($_POST['filepath']))
			$this->_mod_errors[] = $this->l('File error.<br />Cannot write to').' '.$_POST['filepath'];
	}
	
	function getContent()
	{
		$this->_html .= '<h2>'.$this->l('[BETA]Google Base Products Feed').' {compat='.$this->_compat.'}</h2>';
		if(!is_writable(realpath($this->directory())))
			$this->_warnings[] = $this->l('Output directory must be writable or the feed file will need to be pre-created with write permissions.');
  
		if(isset($this->_warnings) AND sizeof($this->_warnings))
		{
		  $this->_displayWarnings($this->_warnings);
		}
  
		if (Tools::getValue('btnUpdate'))
		{
			$this->_postValidation();
  
			if (!sizeof($this->_mod_errors))
			{
				Configuration::updateValue($this->name.'_description', Tools::getValue('description'));
				Configuration::updateValue($this->name.'_filepath', addslashes($_POST['filepath'])); // the Tools class kills the windows file name separators :(
				Configuration::updateValue($this->name.'_gtin', Tools::getValue('gtin'));	// gtin field selection
				Configuration::updateValue($this->name.'_use_supplier', (int)(Tools::isSubmit('use_supplier')));
				Configuration::updateValue($this->name.'_currency', (int)Tools::getValue('currency')); // Feed currency
				Configuration::updateValue($this->name.'_lang', (int)Tools::getValue('language'));	// language to generate feed for
        Configuration::updateValue($this->name.'_country', Tools::getValue('country'));
        // A little fix just in case the % sign gets added....
        Configuration::updateValue($this->name.'_default_tax', str_replace('%','',Tools::getValue('default_tax')));
        Configuration::updateValue($this->name.'_ignore_tax', (int)(Tools::isSubmit('ignore_tax')));
        Configuration::updateValue($this->name.'_ignore_shipping', (int)(Tools::isSubmit('ignore_shipping')));
  
				$this->_getGlobals();
			} else {
				if (isset($this->_mod_errors) AND sizeof($this->_mod_errors)) {
					$this->_displayErrors($this->_mod_errors);
				}
			}
		} else if (Tools::getValue('btnSubmit')) {
			// Go try and generate the feed
			$this->_postProcess();
		}
  
		$this->_displayForm();
		$this->_displayFeed();
  
		return $this->_html;
	}
	
	public function	_displayWarnings($warn)
	{
		$str_output = '';
		if (!empty($warn)) {
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
				foreach($warn as $val)
					$str_output .= '<li>'.$val.'</li>';
				$str_output .= '</ul>';
			}
			$str_output .= '</div>';
		}
		echo $str_output;
	}
	
	/**
	 * Display errors
	 */
	public function _displayErrors()
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
				foreach ($this->_mod_errors AS $error)
					echo '<li>'.$error.'</li>';
				echo '</ol>';
			}
			echo '</div>';
		}
	}
	
	public function getAvailability($product)
	{
		if ($product["quantity"]> 0)
			return $this->l('in stock');
    else if ( self::checkQty($product,1))
			return $this->l('available for order');
		else
			return $this->l('out of stock');
	}
	
	public function getCurrencies($object = true, $active = 1)
	{
		switch ($this->_compat) {
			case '14':
				$tab = Db::getInstance()->ExecuteS('
							SELECT *
							FROM `'._DB_PREFIX_.'currency`
							WHERE `deleted` = 0
							'.($active == 1 ? 'AND `active` = 1' : '').'
							ORDER BY `name` ASC');
			break;
			default:
				$tab = Db::getInstance()->ExecuteS('
							SELECT *
							FROM `'._DB_PREFIX_.'currency`
							WHERE `deleted` = 0
							ORDER BY `name` ASC');
			break;
		}
  
		if ($object)
			foreach ($tab as $key => $currency)
				$tab[$currency['id_currency']] = Currency::getCurrencyInstance($currency['id_currency']);
		return $tab;
	}
	
	public function getLanguages()
	{
		$languages = array();
	
		$result = Db::getInstance()->ExecuteS("
						SELECT `id_lang`, `name`, `iso_code`, `active`
						FROM `"._DB_PREFIX_."lang` WHERE `active` = '1'");
	
		foreach ($result AS $row)
			  $languages[(int)($row['id_lang'])] = array('id_lang' => (int)($row['id_lang']), 'name' => $row['name'], 'iso_code' => $row['iso_code'], 'active' => (int)($row['active']));
		
		return $languages;
	}
	
	private function checkQty($product, $qty)	// copied and amended form classes/Product.php
	{
		if (Pack::isPack((int)$product['id_product']) && !Pack::isInStock((int)$product['id_product']))
			return false;

		if (Product::isAvailableWhenOutOfStock(StockAvailable::outOfStock($product['id_product'])))
			return true;

//		if (isset($this->id_product_attribute))
//			$id_product_attribute = $this->id_product_attribute;
//		else
			$id_product_attribute = 0;

		return ($qty <= StockAvailable::getQuantityAvailableByProduct($product['id_product'], $id_product_attribute));
	}
	
		private function _xmlTaxGroups($id_product)
	{
		  $states = array();
		  $counties = array();
		  $tax_groups = '';
		  
		  // Only 1.4+ supported at present
		  if ((int)$this->_compat < 14) return '';
		  
		  if (Country::containsStates($this->target_country->id)) {
			  // Country default
			  $tax_groups .= $this->_xmlTaxGroup((int)$id_product);
			  $states = State::getStatesByIdCountry($this->target_country->id);
		  
			  foreach ($states AS $state) {
				  // State default
				  $tax_groups .= $this->_xmlTaxGroup((int)$id_product, $state);
				  if (State::hasCounties($state['id_state'])) {
					  $counties = County::getCounties($state['id_state']);
					  foreach ($counties AS $county)
					  {
						// County specific
						$tax_groups .= $this->_xmlTaxGroup((int)$id_product, $state, $county);
					  }
				  }
			  }
		  } else {
			  $tax_groups .= $this->_xmlTaxGroup((int)$id_product);
		  }
		  
		  return $tax_groups;
	}
  
	private function _xmlTaxGroup($id_product, $state = NULL, $county = NULL)
	{
		  $group = "<g:tax>\n";
		  $group .= $this->_xmlElement('g:country', $this->target_country->iso_code);
		  if (is_array($state) && isset($state['iso_code']) && isset($state['id_state'])) {
			  if (is_array($county) && isset($county['name']) && isset($county['id_county']))
				  $group .= $this->_xmlElement('g:region', $county['name']);
			  else
				   $group .= $this->_xmlElement('g:region', $state['iso_code']);
		  }
		  $rate = Tax::getProductTaxRateViaRules((int)$id_product, (int)$this->target_country->id,
												  (int)isset($state['id_state']) ? $state['id_state'] : 0 ,
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
		
		// Only 1.4+ supported at present
		if ((int)$this->_compat < 14) return '';
		
		if (Country::containsStates($this->target_country->id)) {
			// Country default
			$shipping_groups .= $this->_xmlShippingCarriers($product);
			$states = State::getStatesByIdCountry($this->target_country->id);
		   
			 foreach ($states AS $state) {
				 // State specific
				$shipping_groups .= $this->_xmlShippingCarriers($product, $state);
			 }
		} else {
			$shipping_groups .= $this->_xmlShippingCarriers($product);
		}
		
		return $shipping_groups;
	}
  
	private static $cacheZoneCarriers = array();
	private function _zoneCarriers($id_zone)
	{
		$carrier_objects = array();
	
		if (!isset(self::$cacheZoneCarriers[$id_zone])) {
			$carriers = Carrier::getCarriers($this->id_lang, true, false, $id_zone, NULL, 5);
			foreach ($carriers as $k => $row) {
				$carrier = new Carrier((int)$row['id_carrier'], $this->id_lang);
				$shippingMethod = $carrier->getShippingMethod();
				if ($shippingMethod != Carrier::SHIPPING_METHOD_FREE) {
					// Get only carriers that are compliant with shipping method
					if (($shippingMethod == Carrier::SHIPPING_METHOD_WEIGHT AND $carrier->getMaxDeliveryPriceByWeight($id_zone) === false)
					OR ($shippingMethod == Carrier::SHIPPING_METHOD_PRICE AND $carrier->getMaxDeliveryPriceByPrice($id_zone) === false)) {
						unset($carriers[$k]);
						continue ;
					}
				}
				$carrier_objects[] = $carrier;
			}
			self::$cacheZoneCarriers[$id_zone] = $carrier_objects;
		}
		return self::$cacheZoneCarriers[$id_zone];
	}
  
	private function _xmlShippingCarriers($product, $state = NULL)
	{
		$groups = '';
	
		if ($state) {
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
			die (Tools::displayError('Failed to find any valid Carriers for '.$this->country. ' Zone = '.$id_zone));
	
		foreach ($carriers AS $carrier) {
			$groups .= $this->_xmlShippingGroup($product, $carrier, $id_zone, $state);
		}
		return $groups;
	}
  
	private function _xmlShippingGroup($product, $carrier, $id_zone, $state = NULL)
	{
		if (!Validate::isLoadedObject($carrier)) {
			die(Tools::displayError('Fatal error: "no default carrier"'));
		}
		
		$group = "<g:shipping>\n";
		$group .= $this->_xmlElement('g:country', $this->target_country->iso_code);
		if (is_array($state) && isset($state['iso_code']) && isset($state['id_state'])) {
			$group .= $this->_xmlElement('g:region', $state['iso_code']);
		}
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

		if (!Validate::isLoadedObject($carrier)) {
			die(Tools::displayError('Fatal error: "no default carrier"'));
		}
		if (!$carrier->active)
			return $shipping_cost;

		// Free fees if free carrier
		if ($carrier->is_free == 1)
			return 0;

		// Select carrier tax
		if ($useTax AND !Tax::excludeTaxeOption())
			 $carrierTax = Tax::getCarrierTaxRate((int)$carrier->id);

		$configuration = Configuration::getMultiple(array('PS_SHIPPING_FREE_PRICE', 'PS_SHIPPING_HANDLING', 'PS_SHIPPING_METHOD', 'PS_SHIPPING_FREE_WEIGHT'));
		// Free fees
		$free_fees_price = 0;
		if (isset($configuration['PS_SHIPPING_FREE_PRICE']))
			$free_fees_price = Tools::convertPrice((float)($configuration['PS_SHIPPING_FREE_PRICE']), Currency::getCurrencyInstance((int)($this->id_currency)));

		if ($order_total >= (float)($free_fees_price) AND (float)($free_fees_price) > 0)
			return $shipping_cost;
		if (isset($configuration['PS_SHIPPING_FREE_WEIGHT']) AND $product['weight'] >= (float)($configuration['PS_SHIPPING_FREE_WEIGHT']) AND (float)($configuration['PS_SHIPPING_FREE_WEIGHT']) > 0)
			return $shipping_cost;

		// Get shipping cost using correct method
		if ($carrier->range_behavior) {
			if (($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_WEIGHT AND (!Carrier::checkDeliveryPriceByWeight($carrier->id, $this->getTotalWeight(), $id_zone)))
					OR ($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_PRICE AND (!Carrier::checkDeliveryPriceByPrice($carrier->id, $this->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING), $id_zone, (int)($this->id_currency)))))
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
		if (isset($configuration['PS_SHIPPING_HANDLING']) AND $carrier->shipping_handling)
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
}
