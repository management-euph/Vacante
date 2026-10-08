<?php

namespace Tygh\UpgradeCenter\Validators;

class CpAmValidator implements IValidator
{
    protected $name = 'Add-ons Manager';
    protected $status = '';

    public function check($schema, $request)
    {
        if (empty($this->status)) {
            return array(
                false, __('text_addon_install_dependencies', array('[addon]' => 'Cart-Power: Add-ons manager'))
            );
        }

        return array(true, array());
    }

    public function getName()
    {
        return $this->name;
    }

    public function __construct()
    {
        $this->status = \Tygh\Registry::get('addons.cp_addons_manager.status');
    }
}