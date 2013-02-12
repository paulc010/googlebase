googlebase
========

Google product search for Prestashop (to version 1.5.3.1)

Usage
-------

Upload the "googlebase" folder and its contents into your modules directory to use. The feed is generated from within the "Configure" panel of the module. It is located in
the "Advertising & Marketing" section of the Modules list in the Backoffice.

Known Major Issues
----------------------

Apparel and some other product types really need to be able to list the variants as unique items in the feed. This is not currently supported.

See https://github.com/paulc010/googlebase/tree/beta for a 1.5.3 specific version that implements "variants" using product combinations.

CHANGELOG
-------------

12/02/2013
-------------

- Unchecking "Use Supplier Reference" enables the use of "Reference" instead for MPN (https://github.com/paulc010/googlebase/issues/1)
  NOTE: This may behave unpredictably for products with "Combinations"
- Bug fixes to warnings variable
- Change price calculation to reflect default customer group tax settings (include/exclude tax setting)
- Added cron facility for Prestashop 1.5

11/02/2013
-------------

- Modified urls for <g:link/>, <g:image_link/> and <g:additional_image_link/> to encode entities.
- Use context for "Link" object
- Fixed <g:availability/> (robert@irrelevant.com)
- Modified logic for CategoryPosition, in case of legacy upgraded store (robert@irrelevant.com)
- Omit some formatted numeric fields if empty, e.g. <g:gtin/>. Note that the feed will still be invalid as 2 of 3 rule applies to <g:gtin/>, <g:mpn/> and <g:brand/>
- Allow description to be used (with pre-processing) should the short description be empty (robert@irrelevant.com)
- Fix to display the default supplier reference number (this was otherwise broken in 1.5.x) 

10/02/2013
-------------

- Added module to git repository and updated for initial 1.5.x compatibility