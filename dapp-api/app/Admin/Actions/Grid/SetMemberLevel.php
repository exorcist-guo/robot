<?php

namespace App\Admin\Actions\Grid;


use App\Admin\Forms\SetLevel;
use Dcat\Admin\Grid\RowAction;
use Dcat\Admin\Widgets\Modal;

class SetMemberLevel extends RowAction
{

    protected $title = '修改用户等级';


    /**
     * 渲染模态框.
     *
     * @return Modal
     */
    public function render(): Modal
    {
        $form = SetLevel::make()->payload(['id' => $this->getKey()]);

        return Modal::make()
            ->lg()
            ->title(admin_trans_label('Update'))
            ->body($form)
            ->button($this->title);
    }
}
