<?php

namespace App\Admin\Forms;

use App\Models\Member;
use Dcat\Admin\Widgets\Form;

class SetLevel extends Form
{
    /**
     * Handle the form request.
     *
     * @param array $input
     *
     * @return mixed
     */
    public function handle(array $input)
    {
        // 获取异常id
        $issue_id = $this->payload['id'] ?? null;

        // 获取修复说明
        $level = $input['level'] ?? null;

        // 如果没有盘点id返回错误
        if (!$issue_id) {
            return $this->response()
                ->error(trans('main.record_none'));
        }

        if (!$level) {
            return $this->response()
                ->error(trans('等级不能为空'));
        }

        $member = Member::find($issue_id);
        $member->level = $level;
        $member->set_level = 1;
        $member->save();

        return $this->response()
            ->success(trans('main.success'))
            ->refresh();


    }

    /**
     * Build a form here.
     */
    public function form()
    {
        $this->select('level', '等级')->options([
            0 => 'LV0', 1 => 'LV1', 2 => 'LV2', 3 => 'LV3', 4 => 'LV4', 5 => 'LV5',
            6 => 'LV6', 7 => 'LV7', 8 => 'LV8', 9 => 'LV9', 10 => 'LV10',
            11 => 'LV11', 12 => 'LV12', 13 => 'LV13', 14 => 'LV14', 15 => 'LV15',
        ])->required();

    }

    /**
     * The data of the form.
     *
     * @return array
     */
    public function default()
    {

    }
}
