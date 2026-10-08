<?php

use Phinx\Migration\AbstractMigration;

class AlterCpProfileTypes extends AbstractMigration
{ 
    public function up()
    {
        $options = $this->adapter->getOptions();
        $pr = $options['prefix'];

        $table = $this->table("{$pr}cp_profile_types");
        if (!$table->hasColumn('is_default')) {
            $table
                ->addColumn('is_default', 'integer', array('signed' => false, 'null' => false, 'default' => 0))
                ->save();
        }
        if (!$table->hasColumn('id_plans')) {
            $table
                ->addColumn('id_plans', 'string', array('limit' => 128, 'null' => false, 'default' => ''))
                ->save();
        }
    }

    public function down()
    {
        $options = $this->adapter->getOptions();
        $pr = $options['prefix'];

        $table = $this->table("{$pr}cp_profile_types");

        if ($table->hasColumn('is_default')) {
            $table->removeColumn('is_default');
        }
        if ($table->hasColumn('id_plans')) {
            $table->removeColumn('id_plans');
        }
    }
}
