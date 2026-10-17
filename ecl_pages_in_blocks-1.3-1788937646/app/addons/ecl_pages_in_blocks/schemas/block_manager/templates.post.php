<?php
/***************************************************************************
*                                                                          *
*   (c) 2004 Vladimir V. Kalynyak, Alexey V. Vinokurov, Ilya M. Shalnev    *
*                                                                          *
* This  is  commercial  software,  only  users  who have purchased a valid *
* license  and  accept  to the terms of the  License Agreement can install *
* and use this program.                                                    *
*                                                                          *
****************************************************************************
* PLEASE READ THE FULL TEXT  OF THE SOFTWARE  LICENSE   AGREEMENT  IN  THE *
* "copyright.txt" FILE PROVIDED WITH THIS DISTRIBUTION PACKAGE.            *
****************************************************************************/

$schema['addons/ecl_pages_in_blocks/blocks/pages/pages_in_blocks.tpl'] = array (
	'fillings' => array('manually'),
	'params' => array (
		'plain' => true,
		'avail_types' => array(
			'T', 'L', 'F', 'P'
		),
		'get_additions' => true
	)
);
	
return $schema;