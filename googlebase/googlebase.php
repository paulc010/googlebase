<?php

if (!defined('_PS_VERSION_'))
	exit;
	
class GoogleBase extends Module
{
	private $_html = '';
	private $_postErrors = array();
	private $_compat;
	private $_warnings;
	private $_mod_errors;
	
	private $xml_description;
	private $id_lang;
	private $languages;
	private $lang_iso;
	private $id_currency;
	private $currencies;
	private $gtin_field;
	private $use_supplier;
	private $nearby; // Not supported yet. Needs config option

	public function __construct()
	{
		$version_mask = explode('.', _PS_VERSION_, 3);
		
		$this->_compat = (int)($version_mask[0]*10)+$version_mask[1];
		$this->_warnings = array();
		$this->_mod_errors = array();
		
		$this->name = 'googlebase';
		$this->tab = 'advertising_marketing';
		$this->author = 'eCartService.net';
		$this->version = '0.9';
		$this->need_instance = 0;
		
		parent::__construct();
		
		// Set default config values if they don't already exist (here for compatibility in case the user doesn't uninstall/install at upgrade)
		// Also set global "macro" data for the feed and check for store configuration changes
		if ($this->isInstalled($this->name)) {
			
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
			Configuration::updateValue($this->name.'_lang', $this->context->cookie->id_lang);
		if (!Configuration::get($this->name.'_gtin'))
			Configuration::updateValue($this->name.'_gtin', 'ean13');
		if (!Configuration::get($this->name.'_use_supplier'))
			Configuration::updateValue($this->name.'_use_supplier', 'on');
		if (!Configuration::get($this->name.'_currency'))
			Configuration::updateValue($this->name.'_currency', (int)Configuration::get('PS_CURRENCY_DEFAULT'));
		
		$this->_getGlobals();
	
		if (!Configuration::get($this->name.'_filepath'))
			Configuration::updateValue($this->name.'_filepath', addslashes($this->defaultOutputFile()));
	
		// TODO: Needs config option to turn on/off. Currently off.
		$this->_nearby = false;
	}

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
			$this->_warnings[] = $this->l('Language configuration is invalid - reset to default.');
		}
	  
		$this->gtin_field = Configuration::get($this->name.'_gtin');
	  
		$this->use_supplier = Configuration::get($this->name.'_use_supplier');
		// Fix old setting method
		if ($this->use_supplier == '1') {
			Configuration::updateValue($this->name.'_use_supplier', 'on');
			$this->use_supplier = 'on';
		}
	  
		$this->currencies = $this->getCurrencies();
		$this->id_currency = intval(Configuration::get($this->name.'_currency'));
		if (!isset($this->currencies[$this->id_currency]))
		{
			Configuration::updateValue($this->name.'_currency', (int)Configuration::get('PS_CURRENCY_DEFAULT'));
			$this->id_currency = (int)Configuration::get('PS_CURRENCY_DEFAULT');
			$this->_warnings[] = $this->l('Currency configuration is invalid - reset to default.');
		}
	  
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
			return htmlentities($path);
  
		$pipe = ' > ';
  
		// Fix for legacy ordering via numeric prefix
		$category_name = preg_replace('/^[0-9]+\./', '', $category->name);
  
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
					//echo '<pre>'.print_r($product, true).'</pre>';
					//echo '<h2>Product Id: '.$product['id_product'].'</h2><pre>'.print_r(Product::getProductAttributesIds($product['id_product']), true).'</pre>';
					
					// We need to check whether we need to loop for product variants
					$combinations = Product::getProductAttributesIds($product['id_product']);
					if (empty($combinations)) {
						$items .= "<item>\n";
						$items .= $this->_processProduct($product);
						$items .= "</item>\n\n";
					} else {
						foreach ($combinations as $combination) {
							$items .= "<item>\n";
							$items .= $this->_processProduct($product, $combination['id_product_attribute']);
							$items .= "</item>\n\n";
						}
					}
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
	
	private function _processProduct($product, $id_product_attribute = 0)
	{
		$item_data = '';
		if ($id_product_attribute) {
			$variant = new Combination($id_product_attribute);
		} else {
			$variant = '';
		}
		$product_link = $this->_getProductLink($product, $variant);
		$image_links = $this->_getImageLinks($product);
		
		// Reference page: http://www.google.com/support/merchants/bin/answer.py?answer=188494
		
		// 1. Basic Product Information
		
		$item_data .= $this->_xmlElement('g:id',"pc".$this->lang_iso."-".$product['id_product'].'-'.$id_product_attribute);
		if (is_object($variant)) {
			$item_data .= $this->_xmlElement('g:item_group_id', "pc".$this->lang_iso."-".$product['id_product']);
			$variant_labels = $variant->getAttributesName($this->id_lang);
			$variant_name = $product['name']. ' (';
			foreach ($variant_labels as $label) {
				$variant_name .= ' '.$label['name'];
			}
			$variant_name = $variant_name.' )';
			$item_data .= $this->_xmlElement('title',$variant_name, true);
		} else {
			$item_data .= $this->_xmlElement('title',$product['name'], true);
		}
		
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
		
		$item_data .= $this->_xmlElement('g:condition', $this->_getCondition($product['condition']));
		
		// 2. Availability & Price
		$item_data .= $this->_xmlElement('g:availability',$this->getAvailability($product, $id_product_attribute));
		// Price is WITHOUT any reduction
		$price = $this->_getPrice($product['id_product'], $id_product_attribute);
		$item_data .= $this->_xmlElement('g:price', $price);
		// TODO: If there is an active discount, then include it
		$price_with_reduction = $this->_getSalePrice($product['id_product'], $id_product_attribute);
		if ($price_with_reduction !== $price)
			$item_data .= $this->_xmlElement('g:sale_price',$price_with_reduction);
		/*
		// Effective date is in ISO8601 format TODO: Support "sales" somehow - need a way of returning "expiry date" for the reduction
		$items .= "<g:sale_price_effective_date>".Product::getPriceStatic(intval($product['id_product']))."</g:sale_price_effective_date>\n";
		*/
		
		// 3. Unique Product Identifiers
		if ($product['manufacturer_name'])
			$item_data .= $this->_xmlElement('g:brand',$product['manufacturer_name'], true);
		
		// gtin value
		$item_data .= $this->_xmlElement('g:gtin', $this->_getGtinValue($product, $variant));
		
		if ($this->use_supplier == 'on') {
			if (isset($product['id_supplier']) && !empty($product['id_supplier']) || (is_object($variant) && !empty($variant->supplier_reference))) {
				if (!is_object($variant)) {
					$item_data .= $this->_xmlElement('g:mpn',ProductSupplier::getProductSupplierReference($product['id_product'], 0, $product['id_supplier']));
				} else {
					$item_data .= $this->_xmlElement('g:mpn', $variant->supplier_reference);
				}
			}
		} else {
			if (!is_object($variant)) {
					$item_data .= $this->_xmlElement('g:mpn',$product['reference']);
				} else {
					$item_data .= $this->_xmlElement('g:mpn', $variant->reference);
				}
		}
		// 7. Nearby Stores (US & UK only)
		if ($this->nearby)
			$item_data .= $this->_xmlElement('g:online_only',$product['online_only'] == 1 ? 'y' : 'n');
		
		return $item_data;
	}
	
	private function _getGtinValue($product, $variant = null)
	{
		if (!is_object($variant)) {
			$ean13 = $product['ean13'];
			$upc = $product['upc'];
		} else {
			$ean13 = $variant->ean13;
			$upc = $variant->upc;
		}
		
		$gtin = '';
	
		switch ($this->gtin_field) {
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
	
	private function _getCondition($condition)
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
	
	private function _getPrice($id_product, $id_product_attrib = NULL)
	{
		$price = number_format(Tools::convertPrice(Product::getPriceStatic(intval($id_product), true, $id_product_attrib, 6, NULL, false, false), $this->currencies[$this->id_currency]), 2, '.', '');
		
		return $price.' '.$this->currencies[$this->id_currency]->iso_code;
	}
	
	private function _getSalePrice($id_product, $id_product_attrib = NULL)
	{
		$price = number_format(Tools::convertPrice(Product::getPriceStatic(intval($id_product), true, $id_product_attrib, 6), $this->currencies[$this->id_currency]), 2, '.', '');
		
		return $price.' '.$this->currencies[$this->id_currency]->iso_code;
	}
	
	private function _getImageLinks($product, $variant = null)
	{
		// $variant->getCombinationImages($id_lang);
		$image_data = array(array('link' => '', 'valid' => 0), array('link' => '', 'valid' => 0));
		$images = Image::getImages($this->id_lang, $product['id_product']);
		
		if (isset($images[0])) {
			$image_data[0]['link'] = $this->context->link->getImageLink($product['link_rewrite'], (int)$product['id_product'].'-'.(int)$images[0]['id_image']);
			$image_data[0]['valid'] = 1;
		}
		if (isset($images[1])) {
			$image_data[1]['link'] = $this->context->link->getImageLink($product['link_rewrite'], (int)$product['id_product'].'-'.(int)$images[1]['id_image']);
			$image_data[1]['valid'] = 1;
		}

		return $image_data;
	}
	
	private function _getProductLink($product, $variant = null)
	{
		$variant_anchor = '';
		if (is_object($variant)) {
			$product_object = new Product($product['id_product']);
			$variant_anchor = $product_object->getAnchor($variant->id);
		}
		return $this->context->link->getProductLink($product, null, null, null, (int)$this->id_lang).$variant_anchor;
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

		$this->use_supplier = Configuration::get($this->name.'_use_supplier', 'on');
		$this->gtin_field = Tools::getValue('gtin', Configuration::get($this->name.'_gtin'));
		$this->currency = Tools::getValue('currency', Configuration::get($this->name.'_currency'));
		$this->id_lang = Tools::getValue('language', Configuration::get($this->name.'_lang'));
	  
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
						 but <em>will not automatically be used</em>.').'</p>
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
				<p class="clear">'.$this->l('Store default ='). ' ' . $this->languages[$this->context->cookie->id_lang]['name'].'</p>
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
							<input name="filepath" type="text" style="width: 600px;" value="'.(isset($_POST['filepath']) ? $_POST['filepath'] : $this->winFixFilename(Configuration::get($this->name.'_filepath'))).'"/>
							<p class="clear">'.$this->l('Recommended path:').' '.$this->defaultOutputFile().'</p>
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
				<input type="checkbox" name="use_supplier" id="use_supplier" value="on"' . ($this->use_supplier == 'on' ? 'checked="checked" ' : '') . ' />
				<p class="clear">'.$this->l('Use the supplier reference field (default) rather than the reference field as Manufacturers Part Number (MPN)').'</p>
			  </div>
			  <label>'.$this->l('Unique Product Identifier').'</label>
			  <div class="margin-form">
				<input type="radio" name="gtin" id="gtin_0" value="ean13" '.($this->gtin_field == 'ean13' ? 'checked="checked" ' : '').' > EAN13</option>
				<input type="radio" name="gtin" id="gtin_1" value="upc" '.($this->gtin_field == 'upc' ? 'checked="checked" ' : '').' > UPC</option>
				<input type="radio" name="gtin" id="gtin_2" value="none" '.($this->gtin_field == 'none' ? 'checked="checked" ' : '').' > None</option>
				<p class="clear">'.$this->l('Mandatory unless you specify the Manufacturer and MPN (see above). Either: EAN13 (EU) or UPC (US)').'</p>
			  </div>
			  <input name="btnUpdate" id="btnUpdate" class="button" value="'.((!file_exists($this->winFixFilename(Configuration::get($this->name.'_filepath')))) ? $this->l('Update Settings') : $this->l('Update Settings')).'" type="submit" />
					</fieldset>
				</form><br/>';
	}
	
	private function _postValidation()
	{
		// TODO Need to review form validation.....
		// Used $_POST here to allow us to modify them directly - naughty I know :)
		
		Configuration::updateValue($this->name.'_use_supplier', Tools::getValue('use_supplier') ? Tools::getValue('use_supplier') : 'off');
  
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
				Configuration::updateValue($this->name.'_use_supplier', Tools::getValue('use_supplier') ? Tools::getValue('use_supplier') : 'off');
				Configuration::updateValue($this->name.'_currency', (int)Tools::getValue('currency')); // Feed currency
				Configuration::updateValue($this->name.'_lang', (int)Tools::getValue('language'));	// language to generate feed for
  
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
	
	private function	_displayWarnings($warn)
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
				foreach ($this->_mod_errors AS $error)
					echo '<li>'.$error.'</li>';
				echo '</ol>';
			}
			echo '</div>';
		}
	}
	
	public function getAvailability($product, $id_product_attribute  = 0)
	{
		// TODO: Need to check on actual variant availability
		if ($product["quantity"]> 0)
      return $this->l('in stock');
    else if ( self::checkQty($product,1))
      return $this->l('available for order');
    else
      return $this->l('out of stock');
	}
	
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
}
